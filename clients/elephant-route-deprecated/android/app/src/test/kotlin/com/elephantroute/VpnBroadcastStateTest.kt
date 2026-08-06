package com.elephantroute

import org.junit.Assert.assertEquals
import org.junit.Test

class VpnBroadcastStateTest {
    @Test
    fun missingStatusPreservesLastExplicitStatus() {
        val state = VpnBroadcastState()

        assertEquals("connected", state.resolve("connected"))
        assertEquals("connected", state.resolve(null))
        assertEquals("connected", state.resolve(""))
    }

    @Test
    fun explicitStatusReplacesPreviousStatus() {
        val state = VpnBroadcastState(initialStatus = "connected")

        assertEquals("disconnecting", state.resolve("disconnecting"))
        assertEquals("disconnecting", state.resolve(null))
        assertEquals("disconnected", state.resolve("disconnected"))
    }
}
