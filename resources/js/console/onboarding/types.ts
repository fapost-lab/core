import type { ShellPageProps } from '@console/shell'

export type { OnboardingStep, OnboardingTranslations } from '@console/shell'

/** The props of the wizard's pages: the shell's, with the steps always present. */
export type OnboardingPageProps = ShellPageProps & {
  onboarding: { steps: import('@console/shell').OnboardingStep[] }
}
