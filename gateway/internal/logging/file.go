package logging

import (
	"fmt"
	"os"
	"sync"
)

// fileMode keeps log files readable by the owner and its group only: entries
// carry channel fingerprints and platform names, which is not world-readable material.
const fileMode = 0o640

// ReopenableFile is an io.Writer that can swap to a freshly opened file on demand.
//
// This is what lets an external rotator own rotation. logrotate renames the file
// and signals the process; on SIGHUP we reopen the original path and the old
// handle is released. Writing our own size-based rotator would mean reimplementing
// a well-solved problem, and getting it subtly wrong loses log lines under load.
//
// Without the reopen, a rotated-away file stays open forever and every subsequent
// line lands in a file nobody can find.
type ReopenableFile struct {
	mu   sync.Mutex
	path string
	file *os.File
}

// OpenFile opens the log file for appending, creating it when absent.
func OpenFile(path string) (*ReopenableFile, error) {
	file, err := openAppend(path)
	if err != nil {
		return nil, err
	}

	return &ReopenableFile{path: path, file: file}, nil
}

// Write appends to the current file handle.
func (w *ReopenableFile) Write(p []byte) (int, error) {
	w.mu.Lock()
	defer w.mu.Unlock()

	return w.file.Write(p)
}

// Reopen closes the current handle and opens the configured path again.
//
// On failure the previous handle is kept: continuing to write into a rotated file
// still records the entries somewhere, whereas dropping the handle would silence
// the gateway's logging entirely.
func (w *ReopenableFile) Reopen() error {
	replacement, err := openAppend(w.path)
	if err != nil {
		return fmt.Errorf("reopen log file: %w", err)
	}

	w.mu.Lock()
	previous := w.file
	w.file = replacement
	w.mu.Unlock()

	return previous.Close()
}

// Close releases the current handle.
func (w *ReopenableFile) Close() error {
	w.mu.Lock()
	defer w.mu.Unlock()

	return w.file.Close()
}

// Path reports the file this writer targets.
func (w *ReopenableFile) Path() string {
	return w.path
}

func openAppend(path string) (*os.File, error) {
	file, err := os.OpenFile(path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, fileMode)
	if err != nil {
		return nil, fmt.Errorf("open log file %q: %w", path, err)
	}

	return file, nil
}
