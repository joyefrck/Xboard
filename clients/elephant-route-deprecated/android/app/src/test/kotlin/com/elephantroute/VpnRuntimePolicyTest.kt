package com.elephantroute

import io.nekohasekai.libbox.Libbox
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class VpnRuntimePolicyTest {
    @Test
    fun notificationUpdatesAreLimitedToOneEveryTenSeconds() {
        val policy = VpnRuntimePolicy(notificationIntervalMs = 10_000L)

        assertTrue(policy.shouldUpdateNotification(1_000L))
        assertFalse(policy.shouldUpdateNotification(10_999L))
        assertTrue(policy.shouldUpdateNotification(11_000L))
    }

    @Test
    fun releaseSubscribesOnlyToStatus() {
        val policy = VpnRuntimePolicy()

        assertEquals(listOf(Libbox.CommandStatus), policy.commands(isDebug = false))
    }

    @Test
    fun debugAddsGroupsButNeverStreamsCoreLogs() {
        val policy = VpnRuntimePolicy()

        assertEquals(
            listOf(Libbox.CommandStatus, Libbox.CommandGroup),
            policy.commands(isDebug = true),
        )
        assertFalse(policy.commands(isDebug = true).contains(Libbox.CommandLog))
    }

    @Test
    fun singBoxLogLevelIsBoundedByBuildType() {
        val policy = VpnRuntimePolicy()

        assertEquals("warn", policy.singBoxLogLevel(isDebug = false))
        assertEquals("debug", policy.singBoxLogLevel(isDebug = true))
    }
}
