package com.cloudonix.opbx.acd;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

import ch.qos.logback.classic.Level;
import ch.qos.logback.classic.Logger;
import ch.qos.logback.classic.LoggerContext;
import ch.qos.logback.classic.joran.JoranConfigurator;
import ch.qos.logback.classic.spi.ILoggingEvent;
import ch.qos.logback.classic.spi.LoggingEvent;
import ch.qos.logback.core.OutputStreamAppender;
import io.vertx.core.json.JsonObject;
import java.nio.charset.StandardCharsets;
import java.util.Map;
import java.util.Set;
import org.junit.jupiter.api.Test;

// Pins the flat JSON log line the log pipeline (Loki/Grafana) parses, across logback/encoder upgrades.
class LogFormatTest {
    @Test
    @SuppressWarnings("unchecked")
    void logLinesKeepTheSharedJsonContract() throws Exception {
        LoggerContext context = new LoggerContext();
        JoranConfigurator configurator = new JoranConfigurator();
        configurator.setContext(context);
        configurator.doConfigure(LogFormatTest.class.getResource("/logback.xml"));

        Logger root = context.getLogger(org.slf4j.Logger.ROOT_LOGGER_NAME);
        OutputStreamAppender<ILoggingEvent> appender = (OutputStreamAppender<ILoggingEvent>) root.getAppender("JSON");
        LoggingEvent event = new LoggingEvent(Logger.class.getName(), root, Level.WARN, "queue {} full", null, new Object[] {"q1"});
        event.setMDCPropertyMap(Map.of("call_id", "c-1", "org_id", "7", "not_exported", "x"));

        JsonObject line = new JsonObject(new String(appender.getEncoder().encode(event), StandardCharsets.UTF_8));

        assertEquals(Set.of("ts", "msg", "level", "service", "log_type", "call_id", "org_id"), line.fieldNames());
        assertEquals("queue q1 full", line.getString("msg"));
        assertEquals("WARN", line.getString("level"));
        assertEquals("acd-worker", line.getString("service"));
        assertEquals("call_flow", line.getString("log_type"));
        assertEquals("c-1", line.getString("call_id"));
        assertTrue(line.getString("ts").matches("\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{1,9}Z"), line.getString("ts"));
        context.stop();
    }
}
