package com.cloudonix.opbx.acd;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;

import org.junit.jupiter.api.Test;

class ConfigTest {
    @Test
    void rejectsEmptyAndDefaultTokens() {
        assertThrows(IllegalStateException.class, () -> Config.requireToken("T", ""));
        assertThrows(IllegalStateException.class, () -> Config.requireToken("T", null));
        assertThrows(IllegalStateException.class, () -> Config.requireToken("T", "dev-token-change-in-production"));
    }

    @Test
    void acceptsRealToken() {
        assertEquals("abc123", Config.requireToken("T", "abc123"));
    }
}
