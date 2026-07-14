package com.elephantroute

import android.content.Context
import android.util.Log
import io.nekohasekai.libbox.BoxService
import io.nekohasekai.libbox.CommandServer
import io.nekohasekai.libbox.CommandServerHandler
import io.nekohasekai.libbox.Libbox
import io.nekohasekai.libbox.PlatformInterface
import io.nekohasekai.libbox.SetupOptions
import io.nekohasekai.libbox.SystemProxyStatus
import java.io.File

object SingBoxEngine : CommandServerHandler {
    private var commandServer: CommandServer? = null
    private var boxService: BoxService? = null
    private var setupDone = false
    private var lastContext: Context? = null
    private var lastConfig: String? = null
    private var lastPlatform: PlatformInterface? = null
    private const val TAG = "SingBoxEngine"

    @Synchronized
    fun start(context: Context, config: String, platform: PlatformInterface) {
        Log.i(TAG, "=== SingBoxEngine.start() called ===")
        Log.d(TAG, "Setup done: $setupDone, CommandServer exists: ${commandServer != null}")

        try {
            if (!setupDone) {
                val workingDir = File(context.filesDir, "sing-box").apply {
                    if (!exists()) mkdirs()
                }
                val options = SetupOptions().apply {
                    basePath = context.filesDir.absolutePath
                    workingPath = workingDir.absolutePath
                    tempPath = context.cacheDir.absolutePath
                }
                Libbox.setup(options)
                setupDone = true
                Log.i(TAG, "Libbox setup completed; version=${Libbox.version()}")
            }

            if (commandServer == null) {
                commandServer = Libbox.newCommandServer(this, 300).also {
                    it.start()
                }
                Log.i(TAG, "CommandServer started")
            }

            closeBoxService()
            val service = Libbox.newService(config, platform)
            commandServer?.setService(service)
            try {
                service.start()
            } catch (e: Exception) {
                commandServer?.setService(null)
                runCatching { service.close() }
                throw e
            }

            boxService = service
            lastContext = context.applicationContext
            lastConfig = config
            lastPlatform = platform
            Log.i(TAG, "Service started successfully")
        } catch (e: Exception) {
            Log.e(TAG, "FATAL ERROR in SingBoxEngine.start(): ${e.message}", e)
            throw e
        }
    }

    @Synchronized
    fun stop() {
        Log.i(TAG, "=== SingBoxEngine.stop() called ===")
        runCatching { closeBoxService() }
            .onFailure { Log.w(TAG, "Error closing service: ${it.message}") }
        runCatching { commandServer?.close() }
            .onFailure { Log.w(TAG, "Error closing CommandServer: ${it.message}") }
        commandServer = null
        lastContext = null
        lastConfig = null
        lastPlatform = null
        Log.i(TAG, "=== SingBoxEngine.stop() completed ===")
    }

    private fun closeBoxService() {
        val service = boxService ?: return
        boxService = null
        commandServer?.setService(null)
        service.close()
    }

    override fun serviceReload() {
        val context = lastContext ?: return
        val config = lastConfig ?: return
        val platform = lastPlatform ?: return
        start(context, config, platform)
    }

    override fun postServiceClose() {
        Thread { stop() }.start()
    }

    override fun getSystemProxyStatus(): SystemProxyStatus {
        return SystemProxyStatus().apply {
            available = false
            enabled = false
        }
    }

    override fun setSystemProxyEnabled(isEnabled: Boolean) {
        Log.d(TAG, "setSystemProxyEnabled: $isEnabled")
    }
}
