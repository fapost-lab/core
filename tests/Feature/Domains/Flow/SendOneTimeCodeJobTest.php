<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Auth\AuthCodeStore;
use App\Domains\Flow\Auth\OneTimeCodeMail;
use App\Domains\Flow\Auth\OneTimeCodeSenderRegistry;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Jobs\Flow\SendOneTimeCodeJob;
use Fapost\Foundation\Auth\Contracts\OneTimeCodeSenderInterface;
use Fapost\Foundation\Auth\Contracts\OneTimeCodeSenderRegistryInterface;
use Fapost\Foundation\Auth\DTO\OneTimeCodeDelivery;
use Fapost\Foundation\Auth\DTO\OneTimeCodeMessage;
use Fapost\Foundation\Auth\Enums\OneTimeCodeChannel;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\FeatureTestCase;

/**
 * The job that delivers a one-time code: once, through the channel's sender, and nothing of the code in a log.
 */
final class SendOneTimeCodeJobTest extends FeatureTestCase
{
    private const string TENANT = '00000000-0000-0000-0000-000000000001';

    private const string CODE = '482913';

    public function test_core_mails_the_code_once_signed_with_the_assistant(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $session = $this->createSession('Acme Shop');

        $this->runJob($this->job($session));
        $this->runJob($this->job($session));

        Mail::assertSent(OneTimeCodeMail::class, 1);
        Mail::assertSent(OneTimeCodeMail::class, function (OneTimeCodeMail $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('client@example.com')
                && str_contains($html, self::CODE)
                && str_contains($html, 'Acme Shop')
                && 'uk' === $mail->locale;
        });
    }

    public function test_the_job_payload_is_encrypted_and_the_mail_is_never_queued(): void
    {
        $this->assertInstanceOf(ShouldBeEncrypted::class, $this->job('session'));

        $this->expectException(LogicException::class);
        serialize(new OneTimeCodeMail(self::CODE, 'Shop', 10));
    }

    public function test_a_refused_delivery_is_recorded_for_the_node_and_logged_without_the_code(): void
    {
        $session = $this->createSession('Acme Shop');
        $store   = $this->app->make(AuthCodeStore::class);
        $store->issue(self::TENANT, $session, 'auth-1', '1:1', self::CODE, 10);

        $registry = new OneTimeCodeSenderRegistry();
        $registry->register(new class () implements OneTimeCodeSenderInterface {
            public function channel(): OneTimeCodeChannel
            {
                return OneTimeCodeChannel::Email;
            }

            public function send(OneTimeCodeMessage $message): OneTimeCodeDelivery
            {
                return OneTimeCodeDelivery::failed('provider_rejected');
            }
        });
        $this->app->instance(OneTimeCodeSenderRegistryInterface::class, $registry);

        $logged = [];
        Log::listen(static function ($event) use (&$logged): void {
            $logged[] = $event->message . json_encode($event->context);
        });

        $this->runJob($this->job($session));

        $this->assertTrue($store->deliveryFailed(self::TENANT, $session, 'auth-1'));
        $this->assertNotSame([], $logged);
        $this->assertStringNotContainsString(self::CODE, implode("\n", $logged));
        $this->assertStringNotContainsString('client@example.com', implode("\n", $logged));
    }

    public function test_a_package_sender_replaces_core_email_and_a_second_package_is_refused(): void
    {
        $registry = new OneTimeCodeSenderRegistry();
        $core     = $this->sender(OneTimeCodeChannel::Email);
        $package  = $this->sender(OneTimeCodeChannel::Email);

        $registry->setDefault(OneTimeCodeChannel::Email, static fn (): OneTimeCodeSenderInterface => $core);
        $this->assertSame($core, $registry->find(OneTimeCodeChannel::Email));
        $this->assertNull($registry->find(OneTimeCodeChannel::Sms));

        $registry->register($package);
        $this->assertSame($package, $registry->find(OneTimeCodeChannel::Email));

        $this->expectException(LogicException::class);
        $registry->register($this->sender(OneTimeCodeChannel::Email));
    }

    public function test_a_mailer_that_only_logs_leaves_email_codes_without_a_sender(): void
    {
        config(['mail.default' => 'log']);
        $this->assertNull($this->app->make(OneTimeCodeSenderRegistryInterface::class)->find(OneTimeCodeChannel::Email));

        config(['mail.default' => 'smtp']);
        $this->assertNotNull($this->app->make(OneTimeCodeSenderRegistryInterface::class)->find(OneTimeCodeChannel::Email));
    }

    private function sender(OneTimeCodeChannel $channel): OneTimeCodeSenderInterface
    {
        return new readonly class ($channel) implements OneTimeCodeSenderInterface {
            public function __construct(private OneTimeCodeChannel $channel)
            {
            }

            public function channel(): OneTimeCodeChannel
            {
                return $this->channel;
            }

            public function send(OneTimeCodeMessage $message): OneTimeCodeDelivery
            {
                return OneTimeCodeDelivery::sent();
            }
        };
    }

    private function job(string $sessionId): SendOneTimeCodeJob
    {
        return new SendOneTimeCodeJob(
            tenantId: self::TENANT,
            sessionId: $sessionId,
            nodeId: 'auth-1',
            sendToken: '1:1',
            channel: OneTimeCodeChannel::Email->value,
            destination: 'client@example.com',
            code: self::CODE,
            locale: 'uk',
            expiresInMinutes: 10,
        );
    }

    private function runJob(SendOneTimeCodeJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    private function createSession(string $assistantName): string
    {
        $assistant = Assistant::factory()->create(['name' => $assistantName]);
        $contact   = Contact::factory()->forTenant(self::TENANT)->create();
        $flow      = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Auth flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        return (string) FlowSession::query()->create([
            'tenant_id'          => self::TENANT,
            'assistant_id'       => $assistant->id,
            'contact_id'         => $contact->id,
            'flow_definition_id' => $flow->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'auth-1',
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'version'            => 0,
        ])->getKey();
    }
}
