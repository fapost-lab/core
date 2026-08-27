// Package logging builds the gateway's structured logger and the plumbing that
// lets an external rotator manage its files.
//
// Two rules shape everything here. Log records are machine-read first, so the
// default format is JSON. And the gateway sits on the public edge handling other
// people's credentials, so what must never be logged is as much part of the
// design as what is: raw webhook hashes, signature headers, and request bodies
// stay out, and only fingerprints, sizes and verdicts go in.
package logging

import (
	"fmt"
	"io"
	"log/slog"
	"os"
	"strings"
)

// Format selects the encoding of log records.
type Format string

const (
	// FormatJSON emits one JSON object per record, for log shippers.
	FormatJSON Format = "json"

	// FormatText emits key=value pairs, readable during development.
	FormatText Format = "text"
)

// Destination selects where records are written.
type Destination string

const (
	// DestinationStdout writes to standard output and leaves retention to the
	// supervisor — journald, Docker, Kubernetes. This is the default because it
	// keeps rotation out of this process entirely.
	DestinationStdout Destination = "stdout"

	// DestinationFile writes to a path this process owns, reopened on demand so
	// that logrotate can rotate underneath it.
	DestinationFile Destination = "file"
)

// Options describes the desired logger.
type Options struct {
	Level       slog.Level
	Format      Format
	Destination Destination

	// Path is required when Destination is DestinationFile.
	Path string
}

// Logger bundles the slog logger with the file handle backing it, when there is one.
type Logger struct {
	*slog.Logger

	file *ReopenableFile
}

// New builds a logger from the given options.
func New(options Options) (*Logger, error) {
	writer, file, err := destination(options)
	if err != nil {
		return nil, err
	}

	handlerOptions := &slog.HandlerOptions{Level: options.Level}

	var handler slog.Handler
	if options.Format == FormatText {
		handler = slog.NewTextHandler(writer, handlerOptions)
	} else {
		handler = slog.NewJSONHandler(writer, handlerOptions)
	}

	return &Logger{Logger: slog.New(handler), file: file}, nil
}

// Reopen swaps to a freshly opened log file.
//
// Called from the SIGHUP handler. It is a no-op when logging to stdout, where
// rotation belongs to the supervisor and reopening would mean nothing.
func (l *Logger) Reopen() error {
	if l.file == nil {
		return nil
	}

	return l.file.Reopen()
}

// Close releases the log file, if any.
func (l *Logger) Close() error {
	if l.file == nil {
		return nil
	}

	return l.file.Close()
}

// RotatesExternally reports whether an external rotator drives this logger,
// which is what makes SIGHUP meaningful.
func (l *Logger) RotatesExternally() bool {
	return l.file != nil
}

func destination(options Options) (io.Writer, *ReopenableFile, error) {
	if options.Destination != DestinationFile {
		return os.Stdout, nil, nil
	}

	if options.Path == "" {
		return nil, nil, fmt.Errorf("logging: destination %q requires a path", DestinationFile)
	}

	file, err := OpenFile(options.Path)
	if err != nil {
		return nil, nil, err
	}

	return file, file, nil
}

// ParseLevel maps a configured name onto a level, defaulting to info.
//
// An unrecognized name must not silence the logger, so it falls back to info
// rather than failing the process or picking a quieter level.
func ParseLevel(name string) slog.Level {
	switch strings.ToLower(strings.TrimSpace(name)) {
	case "debug":
		return slog.LevelDebug
	case "warn", "warning":
		return slog.LevelWarn
	case "error":
		return slog.LevelError
	default:
		return slog.LevelInfo
	}
}

// ParseFormat maps a configured name onto a format, defaulting to JSON.
func ParseFormat(name string) Format {
	if strings.EqualFold(strings.TrimSpace(name), string(FormatText)) {
		return FormatText
	}

	return FormatJSON
}

// ParseDestination maps a configured name onto a destination, defaulting to stdout.
func ParseDestination(name string) Destination {
	if strings.EqualFold(strings.TrimSpace(name), string(DestinationFile)) {
		return DestinationFile
	}

	return DestinationStdout
}
