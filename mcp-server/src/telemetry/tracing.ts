import { NodeSDK } from "@opentelemetry/sdk-node";
import { OTLPTraceExporter } from "@opentelemetry/exporter-trace-otlp-http";
import { getNodeAutoInstrumentations } from "@opentelemetry/auto-instrumentations-node";
import {
  trace,
  context as otelContext,
  SpanStatusCode,
  type Span,
} from "@opentelemetry/api";
import type { Config } from "../config/index.js";
import type { Logger } from "pino";

let sdk: NodeSDK | undefined;

/** Start OpenTelemetry. No-op-ish when no OTLP endpoint is configured (tracer stays a no-op proxy). */
export function startTelemetry(config: Config, logger: Logger): void {
  if (!config.OTEL_EXPORTER_OTLP_ENDPOINT) {
    logger.info("OTEL_EXPORTER_OTLP_ENDPOINT not set; tracing disabled");
    return;
  }
  sdk = new NodeSDK({
    serviceName: config.OTEL_SERVICE_NAME,
    traceExporter: new OTLPTraceExporter({
      url: `${config.OTEL_EXPORTER_OTLP_ENDPOINT.replace(/\/+$/, "")}/v1/traces`,
    }),
    instrumentations: [
      getNodeAutoInstrumentations({
        // Fastify/http auto-instrumentation covers inbound + outbound fetch spans.
        "@opentelemetry/instrumentation-fs": { enabled: false },
      }),
    ],
  });
  sdk.start();
  logger.info({ endpoint: config.OTEL_EXPORTER_OTLP_ENDPOINT }, "OpenTelemetry tracing started");
}

export async function stopTelemetry(): Promise<void> {
  await sdk?.shutdown();
  sdk = undefined;
}

const tracer = trace.getTracer("opbx-mcp");

/** Run `fn` inside a child span of the current context, recording errors. */
export async function withSpan<T>(
  name: string,
  attributes: Record<string, string | number | boolean | undefined>,
  fn: (span: Span) => Promise<T>,
): Promise<T> {
  return tracer.startActiveSpan(name, async (span) => {
    for (const [k, v] of Object.entries(attributes)) {
      if (v !== undefined) span.setAttribute(k, v);
    }
    try {
      const result = await fn(span);
      span.setStatus({ code: SpanStatusCode.OK });
      return result;
    } catch (err) {
      span.setStatus({
        code: SpanStatusCode.ERROR,
        message: err instanceof Error ? err.message : String(err),
      });
      span.recordException(err as Error);
      throw err;
    } finally {
      span.end();
    }
  });
}

/** Current trace id for log correlation, if a span is active. */
export function currentTraceId(): string | undefined {
  const span = trace.getSpan(otelContext.active());
  const id = span?.spanContext().traceId;
  return id && id !== "00000000000000000000000000000000" ? id : undefined;
}
