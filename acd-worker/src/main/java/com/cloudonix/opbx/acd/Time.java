package com.cloudonix.opbx.acd;

/** Injectable clock for testability. */
public interface Time {
    long nowMillis();

    static Time system() {
        return System::currentTimeMillis;
    }
}
