package config

import "testing"

func validCfg() *Config {
	return &Config{
		LaravelAPIURL: "http://x", LaravelAPIToken: "realtoken", RedisHost: "r", RedisPort: "6379",
		WorkerID: "w", PollInterval: 1, WebhookPort: "2",
	}
}

func TestValidateRejectsEmptyAndDefaultToken(t *testing.T) {
	if err := validCfg().Validate(); err != nil {
		t.Fatalf("valid config rejected: %v", err)
	}
	for _, tok := range []string{"", "dev-token-change-in-production"} {
		c := validCfg()
		c.LaravelAPIToken = tok
		if c.Validate() == nil {
			t.Fatalf("token %q should be rejected", tok)
		}
	}
}
