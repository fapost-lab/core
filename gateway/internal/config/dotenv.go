package config

import (
	"bufio"
	"os"
	"regexp"
	"strings"
)

// interpolation matches ${VAR}, the only expansion Laravel .env files use.
var interpolation = regexp.MustCompile(`\$\{([A-Za-z_][A-Za-z0-9_]*)\}`)

// LoadDotenv reads the shared .env file so the gateway and the application are
// configured from one place.
//
// Only keys absent from the real environment are applied: a value exported by
// systemd or the container runtime must win over a file checked out beside the
// code, otherwise deployments cannot override anything.
//
// A missing file is not an error. In containers the environment is usually
// injected directly and no .env exists.
//
// Nothing here is read eagerly beyond the file itself. Which keys the gateway
// actually consumes is decided in Load, deliberately narrow: the shared file
// also holds the application key, database passwords and provider tokens, and
// the process most exposed to the internet has no business holding those.
func LoadDotenv(path string) error {
	file, err := os.Open(path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}

		return err
	}
	defer file.Close()

	values := map[string]string{}
	scanner := bufio.NewScanner(file)

	for scanner.Scan() {
		key, value, ok := parseLine(scanner.Text())
		if !ok {
			continue
		}

		values[key] = expand(value, values)
	}

	if err := scanner.Err(); err != nil {
		return err
	}

	for key, value := range values {
		if _, present := os.LookupEnv(key); !present {
			if err := os.Setenv(key, value); err != nil {
				return err
			}
		}
	}

	return nil
}

func parseLine(line string) (string, string, bool) {
	line = strings.TrimSpace(line)

	if line == "" || strings.HasPrefix(line, "#") {
		return "", "", false
	}

	line = strings.TrimPrefix(line, "export ")

	key, value, found := strings.Cut(line, "=")
	if !found {
		return "", "", false
	}

	key = strings.TrimSpace(key)
	if key == "" {
		return "", "", false
	}

	return key, unquote(strings.TrimSpace(value)), true
}

// unquote strips a matching pair of quotes. Only unquoted values may carry a
// trailing comment, matching how dotenv files are conventionally read.
func unquote(value string) string {
	if len(value) >= 2 {
		first, last := value[0], value[len(value)-1]
		if (first == '"' && last == '"') || (first == '\'' && last == '\'') {
			return value[1 : len(value)-1]
		}
	}

	if index := strings.Index(value, " #"); index >= 0 {
		value = value[:index]
	}

	return strings.TrimSpace(value)
}

// expand resolves ${VAR} against earlier entries in the same file, then the
// process environment, mirroring how the application reads these files.
func expand(value string, seen map[string]string) string {
	return interpolation.ReplaceAllStringFunc(value, func(match string) string {
		name := match[2 : len(match)-1]

		if resolved, ok := seen[name]; ok {
			return resolved
		}

		return os.Getenv(name)
	})
}
