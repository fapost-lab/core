package logging

import (
	"encoding/json"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestFingerprintHidesTheValue(t *testing.T) {
	const hash = "3f8a9c2e4b7d1a6f5e0c8b3d9a2f7e1c4b6d8a0f2e5c7b9d"

	got := Fingerprint(hash)

	if got == hash {
		t.Fatal("fingerprint returned the raw value")
	}

	if strings.Contains(hash, got) {
		t.Fatal("fingerprint is a substring of the raw value")
	}

	if len(got) != fingerprintBytes*2 {
		t.Fatalf("fingerprint length = %d, want %d hex characters", len(got), fingerprintBytes*2)
	}
}

func TestFingerprintIsStableAndDistinct(t *testing.T) {
	if Fingerprint("alpha") != Fingerprint("alpha") {
		t.Fatal("fingerprint is not stable across calls")
	}

	if Fingerprint("alpha") == Fingerprint("beta") {
		t.Fatal("different values produced the same fingerprint")
	}
}

// An absent value must stay visibly absent rather than becoming the digest of an
// empty string, which would read as a real channel in the logs.
func TestFingerprintOfEmptyStringIsEmpty(t *testing.T) {
	if got := Fingerprint(""); got != "" {
		t.Fatalf("Fingerprint(\"\") = %q, want empty", got)
	}
}

// Reproduces what logrotate does: rename the live file, then signal the process.
// Without a reopen the process keeps writing into the renamed file, and every
// entry after the first rotation lands somewhere nobody looks.
func TestReopenFollowsAnExternalRotation(t *testing.T) {
	directory := t.TempDir()
	path := filepath.Join(directory, "gateway.log")

	writer, err := OpenFile(path)
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer writer.Close()

	if _, err := writer.Write([]byte("before\n")); err != nil {
		t.Fatalf("write before rotation: %v", err)
	}

	rotated := filepath.Join(directory, "gateway.log.1")
	if err := os.Rename(path, rotated); err != nil {
		t.Fatalf("rotate: %v", err)
	}

	if err := writer.Reopen(); err != nil {
		t.Fatalf("reopen: %v", err)
	}

	if _, err := writer.Write([]byte("after\n")); err != nil {
		t.Fatalf("write after rotation: %v", err)
	}

	assertFileContains(t, rotated, "before")
	assertFileContains(t, path, "after")
	assertFileLacks(t, path, "before")
}

func TestReopenCreatesTheFileWhenRotatorRemovedIt(t *testing.T) {
	path := filepath.Join(t.TempDir(), "gateway.log")

	writer, err := OpenFile(path)
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer writer.Close()

	if err := os.Remove(path); err != nil {
		t.Fatalf("remove: %v", err)
	}

	if err := writer.Reopen(); err != nil {
		t.Fatalf("reopen: %v", err)
	}

	if _, err := writer.Write([]byte("recreated\n")); err != nil {
		t.Fatalf("write: %v", err)
	}

	assertFileContains(t, path, "recreated")
}

func TestFileLoggerWritesJSONRecords(t *testing.T) {
	path := filepath.Join(t.TempDir(), "gateway.log")

	logger, err := New(Options{Level: slog.LevelInfo, Format: FormatJSON, Destination: DestinationFile, Path: path})
	if err != nil {
		t.Fatalf("new logger: %v", err)
	}
	defer logger.Close()

	logger.Info("webhook accepted", "channel", Fingerprint("hash"), "platform", "telegram")

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read log: %v", err)
	}

	var record map[string]any
	if err := json.Unmarshal(raw, &record); err != nil {
		t.Fatalf("record is not JSON: %v (%s)", err, raw)
	}

	if record["msg"] != "webhook accepted" {
		t.Errorf("msg = %v, want %q", record["msg"], "webhook accepted")
	}

	if record["platform"] != "telegram" {
		t.Errorf("platform = %v, want telegram", record["platform"])
	}
}

func TestLevelFiltersQuieterRecords(t *testing.T) {
	path := filepath.Join(t.TempDir(), "gateway.log")

	logger, err := New(Options{Level: slog.LevelWarn, Format: FormatJSON, Destination: DestinationFile, Path: path})
	if err != nil {
		t.Fatalf("new logger: %v", err)
	}
	defer logger.Close()

	logger.Info("routine")
	logger.Warn("suspicious")

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read log: %v", err)
	}

	if strings.Contains(string(raw), "routine") {
		t.Error("info record was written at warn level")
	}

	if !strings.Contains(string(raw), "suspicious") {
		t.Error("warn record is missing")
	}
}

// SIGHUP is delivered regardless of destination, so reopening a stdout logger
// must be a harmless no-op rather than an error the signal handler has to special-case.
func TestReopenIsANoOpForStdout(t *testing.T) {
	logger, err := New(Options{Level: slog.LevelInfo, Destination: DestinationStdout})
	if err != nil {
		t.Fatalf("new logger: %v", err)
	}

	if logger.RotatesExternally() {
		t.Error("stdout logger claims external rotation")
	}

	if err := logger.Reopen(); err != nil {
		t.Errorf("Reopen() = %v, want nil", err)
	}

	if err := logger.Close(); err != nil {
		t.Errorf("Close() = %v, want nil", err)
	}
}

func TestFileDestinationRequiresAPath(t *testing.T) {
	if _, err := New(Options{Destination: DestinationFile}); err == nil {
		t.Fatal("expected an error for a file destination without a path")
	}
}

func TestParseHelpersDefaultToSafeValues(t *testing.T) {
	if got := ParseLevel("nonsense"); got != slog.LevelInfo {
		t.Errorf("ParseLevel(nonsense) = %v, want info — an unknown name must not silence logging", got)
	}

	if got := ParseLevel("WARNING"); got != slog.LevelWarn {
		t.Errorf("ParseLevel(WARNING) = %v, want warn", got)
	}

	if got := ParseFormat(" Text "); got != FormatText {
		t.Errorf("ParseFormat( Text ) = %v, want text", got)
	}

	if got := ParseFormat("nonsense"); got != FormatJSON {
		t.Errorf("ParseFormat(nonsense) = %v, want json", got)
	}

	if got := ParseDestination("FILE"); got != DestinationFile {
		t.Errorf("ParseDestination(FILE) = %v, want file", got)
	}

	if got := ParseDestination("nonsense"); got != DestinationStdout {
		t.Errorf("ParseDestination(nonsense) = %v, want stdout", got)
	}
}

func assertFileContains(t *testing.T, path, want string) {
	t.Helper()

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read %s: %v", path, err)
	}

	if !strings.Contains(string(raw), want) {
		t.Errorf("%s does not contain %q, got %q", filepath.Base(path), want, raw)
	}
}

func assertFileLacks(t *testing.T, path, unwanted string) {
	t.Helper()

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read %s: %v", path, err)
	}

	if strings.Contains(string(raw), unwanted) {
		t.Errorf("%s unexpectedly contains %q", filepath.Base(path), unwanted)
	}
}
