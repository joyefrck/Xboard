package com.elephantroute

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class VpnCommandClientLifecycleTest {
    @Test
    fun newerAttemptInvalidatesOlderAttempt() {
        val lifecycle = VpnCommandClientLifecycle()
        val first = lifecycle.beginConnect()
        val second = lifecycle.beginConnect()

        assertFalse(lifecycle.isCurrent(first, isConnected = true))
        assertTrue(lifecycle.isCurrent(second, isConnected = true))
    }

    @Test
    fun disconnectedStateRejectsCurrentAttempt() {
        val lifecycle = VpnCommandClientLifecycle()
        val token = lifecycle.beginConnect()

        assertFalse(lifecycle.isCurrent(token, isConnected = false))
    }

    @Test
    fun invalidateRejectsPendingAttempt() {
        val lifecycle = VpnCommandClientLifecycle()
        val token = lifecycle.beginConnect()

        lifecycle.invalidate()

        assertFalse(lifecycle.isCurrent(token, isConnected = true))
    }

    @Test
    fun destroyRejectsCurrentAndFutureAttempts() {
        val lifecycle = VpnCommandClientLifecycle()
        val existing = lifecycle.beginConnect()

        lifecycle.destroy()
        val afterDestroy = lifecycle.beginConnect()

        assertFalse(lifecycle.isCurrent(existing, isConnected = true))
        assertFalse(lifecycle.isCurrent(afterDestroy, isConnected = true))
    }

    @Test
    fun staleAttemptCannotPublishAfterInvalidation() {
        val lifecycle = VpnCommandClientLifecycle()
        val token = lifecycle.beginConnect()
        lifecycle.invalidate()
        var published = false

        assertFalse(
            lifecycle.publishIfCurrent(token, isConnected = true) {
                published = true
            },
        )
        assertFalse(published)
    }

    @Test
    fun currentConnectedAttemptPublishesOnce() {
        val lifecycle = VpnCommandClientLifecycle()
        val token = lifecycle.beginConnect()
        var published = false

        assertTrue(
            lifecycle.publishIfCurrent(token, isConnected = true) {
                published = true
            },
        )
        assertTrue(published)
    }
}
