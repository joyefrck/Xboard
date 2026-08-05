<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $title }} - App 下载管理</title>
  <style>
    body {
      margin: 0;
      color: #0f172a;
      background: #f8fafc;
      font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    header, main {
      max-width: 1180px;
      margin: 0 auto;
      padding: 20px;
    }
    header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    h1, h2 {
      margin: 0;
    }
    .muted {
      color: #64748b;
      font-size: 13px;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 16px;
      align-items: start;
    }
    .card {
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: #fff;
      padding: 16px;
      box-shadow: 0 1px 2px rgb(15 23 42 / 0.04);
    }
    label {
      display: block;
      margin-top: 12px;
      color: #334155;
      font-size: 13px;
      font-weight: 600;
    }
    input, select, textarea {
      box-sizing: border-box;
      width: 100%;
      margin-top: 6px;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      padding: 9px 10px;
      color: #0f172a;
      font: inherit;
      background: #fff;
    }
    textarea {
      min-height: 92px;
      resize: vertical;
    }
    .field-inline {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 12px;
      color: #334155;
      font-size: 13px;
      font-weight: 600;
    }
    button, .link-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      padding: 8px 12px;
      color: #0f172a;
      background: #fff;
      font: inherit;
      font-size: 13px;
      cursor: pointer;
      text-decoration: none;
    }
    button.primary {
      border-color: #0f172a;
      color: #fff;
      background: #0f172a;
    }
    button.danger {
      border-color: #fecdd3;
      color: #be123c;
      background: #fff1f2;
    }
    button.warn {
      border-color: #fed7aa;
      color: #c2410c;
      background: #fff7ed;
    }
    button:disabled {
      cursor: not-allowed;
      opacity: 0.65;
    }
    .upload-progress {
      display: none;
      margin-top: 14px;
    }
    .upload-progress.show {
      display: block;
    }
    .upload-progress-track {
      overflow: hidden;
      height: 10px;
      border-radius: 999px;
      background: #e2e8f0;
    }
    .upload-progress-bar {
      width: 0%;
      height: 100%;
      border-radius: inherit;
      background: #0f172a;
      transition: width 180ms ease;
    }
    .upload-progress-text {
      margin-top: 6px;
      color: #475569;
      font-size: 13px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }
    th, td {
      border-bottom: 1px solid #e2e8f0;
      padding: 10px 8px;
      text-align: left;
      vertical-align: top;
    }
    th {
      color: #475569;
      background: #f8fafc;
      font-weight: 600;
    }
    .row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
    }
    .badge {
      display: inline-flex;
      border-radius: 999px;
      padding: 2px 8px;
      color: #475569;
      background: #f1f5f9;
      font-size: 12px;
    }
    .badge.ok {
      color: #047857;
      background: #d1fae5;
    }
    .badge.off {
      color: #be123c;
      background: #ffe4e6;
    }
    #status {
      display: none;
      margin: 0 20px;
      border-radius: 6px;
      padding: 10px 12px;
      font-size: 13px;
    }
    #status.show {
      display: block;
    }
    #status.ok {
      color: #047857;
      background: #d1fae5;
    }
    #status.error {
      color: #be123c;
      background: #ffe4e6;
    }
    .modal[aria-hidden="true"] {
      display: none;
    }
    .modal {
      position: fixed;
      z-index: 50;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      box-sizing: border-box;
      padding: 20px;
      background: rgb(15 23 42 / 0.58);
      backdrop-filter: blur(2px);
    }
    .modal-panel {
      width: min(560px, 100%);
      max-height: calc(100vh - 40px);
      overflow: auto;
      border-radius: 10px;
      background: #fff;
      padding: 20px;
      box-shadow: 0 24px 60px rgb(15 23 42 / 0.28);
    }
    .modal-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
    }
    .modal-close {
      min-width: 36px;
      padding: 7px;
      font-size: 18px;
      line-height: 1;
    }
    .locked-fields {
      margin-top: 14px;
      border-radius: 6px;
      padding: 10px 12px;
      color: #475569;
      background: #f8fafc;
      font-size: 13px;
      line-height: 1.7;
    }
  </style>
</head>
<body>
  <header>
    <div>
      <h1>App 下载管理</h1>
      <p class="muted">管理应用、安装包发布、下架和删除。</p>
    </div>
    <a class="link-button" href="/{{ $secure_path }}">返回后台</a>
  </header>

  <div id="status"></div>

  <main class="grid">
    <section class="card">
      <h2>下载验证</h2>
      <p class="muted">独立于系统安全里的启用验证码，只用于安装包下载。</p>
      <form id="download-settings-form">
        <label class="field-inline"><input style="width:auto;margin:0" type="checkbox" name="app_download_turnstile_enable"> 启用下载 Turnstile 验证</label>
        <label>下载 Turnstile Site Key<input name="app_download_turnstile_site_key" placeholder="1x00000000000000000000AA"></label>
        <label>下载 Turnstile Secret Key<input name="app_download_turnstile_secret_key" placeholder="1x0000000000000000000000000000000AA"></label>
        <p class="muted" id="download-settings-hint" style="margin-top:10px"></p>
        <div class="row" style="margin-top:14px">
          <button class="primary" type="submit">保存下载验证</button>
        </div>
      </form>
    </section>

    <section class="card">
      <h2>发布安装包</h2>
      <p class="muted">先选择安装包，系统会自动识别应用名称和平台，提交后会自动创建或复用应用并发布版本。</p>
      <form id="package-form" enctype="multipart/form-data">
        <input type="hidden" name="app_id">
        <input type="hidden" name="channel" value="stable">
        <input type="hidden" name="arch">
        <input type="hidden" name="build_number">
        <input type="hidden" name="min_supported_build" value="0">
        <input type="hidden" name="is_force" value="0">
        <input type="hidden" name="is_enabled" value="1">
        <label>安装包<input type="file" name="artifact" required></label>
        <label>应用类型
          <select name="distribution_scope" required>
            <option value="download_only" selected>第三方 App（仅供下载）</option>
            <option value="official_update">大象官方 App（支持自动更新）</option>
          </select>
        </label>
        <label>应用名称<input name="app_name" required placeholder="例如 Clash Verge"></label>
        <label>应用标识<input name="app_key" pattern="[a-z0-9][a-z0-9-]*[a-z0-9]" placeholder="例如 elephant-route-android"></label>
        <p class="muted">第三方 App 的名称和标识可自行维护，同一个软件的后续版本必须保持一致，且不能使用大象官方保留标识；官方 App 会按平台自动锁定名称和标识，Android 名称固定为“大象网络官方App安卓版”，Windows/macOS 共用 elephant-route-desktop，名称固定为“大象网络官方App桌面版”。</p>
        <label>版本号<input name="version" required placeholder="例如 1.1.0"></label>
        <label>平台
          <select name="platform" required>
            <option value="android">Android</option>
            <option value="windows">Windows</option>
            <option value="macos">macOS</option>
            <option value="ios">iOS</option>
            <option value="linux">Linux</option>
          </select>
        </label>
        <label>描述 / 发布说明<textarea name="release_notes" placeholder="用于公开下载页展示"></textarea></label>
        <div class="row" style="margin-top:14px">
          <button class="primary" type="submit">发布安装包</button>
          <button type="button" id="reset-package">清空</button>
        </div>
        <div class="upload-progress" id="upload-progress">
          <div class="upload-progress-track">
            <div class="upload-progress-bar" id="upload-progress-bar"></div>
          </div>
          <div class="upload-progress-text" id="upload-progress-text">等待上传</div>
        </div>
      </form>
    </section>
  </main>

  <main>
    <section class="card">
      <div class="row" style="justify-content:space-between;margin-bottom:12px">
        <div>
          <h2>版本包</h2>
          <p class="muted">已发布版本需要先下架再删除。</p>
        </div>
        <button type="button" id="refresh">刷新</button>
      </div>
      <div style="overflow:auto">
        <table>
          <thead>
            <tr>
              <th>应用</th>
              <th>版本号</th>
              <th>平台</th>
              <th>安装包</th>
              <th>下载次数</th>
              <th>状态</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody id="version-rows"></tbody>
        </table>
      </div>
    </section>
  </main>

  <div
    class="modal"
    id="version-edit-modal"
    aria-hidden="true"
    role="dialog"
    aria-modal="true"
    aria-labelledby="version-edit-title"
  >
    <div class="modal-panel">
      <div class="modal-header">
        <div>
          <h2 id="version-edit-title">编辑已发布版本</h2>
          <p class="muted" style="margin-bottom:0">保存后保持当前上架状态，并立即更新公开下载信息。</p>
        </div>
        <button class="modal-close" type="button" id="version-edit-close" aria-label="关闭">×</button>
      </div>
      <div class="locked-fields">
        <div><strong>应用 / 平台：</strong><span id="version-edit-identity">-</span></div>
        <div><strong>应用类型：</strong><span id="version-edit-app-type">-</span></div>
        <div><strong>当前安装包：</strong><span id="version-edit-current-artifact">-</span></div>
      </div>
      <form id="version-edit-form" enctype="multipart/form-data">
        <input type="hidden" name="id">
        <label>版本号<input name="version" required maxlength="32"></label>
        <label>发布说明<textarea name="release_notes" maxlength="20000" placeholder="用于公开下载页展示"></textarea></label>
        <label>新安装包（可选）<input type="file" name="artifact"></label>
        <p class="muted">未选择文件时只更新版本号和发布说明；选择新安装包将替换并删除旧文件，累计下载次数保持不变。</p>
        <div class="row" style="margin-top:14px">
          <button class="primary" type="submit">保存修改</button>
          <button type="button" id="version-edit-cancel">取消</button>
        </div>
        <div class="upload-progress" id="version-edit-progress">
          <div class="upload-progress-track">
            <div class="upload-progress-bar" id="version-edit-progress-bar"></div>
          </div>
          <div class="upload-progress-text" id="version-edit-progress-text">等待保存</div>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      var securePath = @json($secure_path);
      var apiBase = "/api/v2/" + securePath + "/app-package";
      var apps = [];
      var versions = [];
      var statusEl = document.getElementById("status");
      var packageForm = document.getElementById("package-form");
      var downloadSettingsForm = document.getElementById("download-settings-form");
      var downloadSettingsHint = document.getElementById("download-settings-hint");
      var appInput = packageForm.querySelector('[name="app_id"]');
      var artifactInput = packageForm.querySelector('[name="artifact"]');
      var packageSubmitButton = packageForm.querySelector('button[type="submit"]');
      var resetPackageButton = document.getElementById("reset-package");
      var uploadProgress = document.getElementById("upload-progress");
      var uploadProgressBar = document.getElementById("upload-progress-bar");
      var uploadProgressText = document.getElementById("upload-progress-text");
      var versionRows = document.getElementById("version-rows");
      var versionEditModal = document.getElementById("version-edit-modal");
      var versionEditForm = document.getElementById("version-edit-form");
      var versionEditIdentity = document.getElementById("version-edit-identity");
      var versionEditAppType = document.getElementById("version-edit-app-type");
      var versionEditCurrentArtifact = document.getElementById("version-edit-current-artifact");
      var versionEditClose = document.getElementById("version-edit-close");
      var versionEditCancel = document.getElementById("version-edit-cancel");
      var versionEditSubmit = versionEditForm.querySelector('button[type="submit"]');
      var versionEditProgress = document.getElementById("version-edit-progress");
      var versionEditProgressBar = document.getElementById("version-edit-progress-bar");
      var versionEditProgressText = document.getElementById("version-edit-progress-text");
      var versionEditBusy = false;

      function token() {
        var raw = localStorage.getItem("XBOARD_ACCESS_TOKEN") || localStorage.getItem("access_token");
        if (!raw) {
          return "";
        }
        try {
          var parsed = JSON.parse(raw);
          return parsed && parsed.value ? parsed.value : raw;
        } catch (e) {
          return raw;
        }
      }

      function showStatus(message, ok) {
        statusEl.textContent = message;
        statusEl.className = "show " + (ok ? "ok" : "error");
        window.setTimeout(function () {
          statusEl.className = "";
        }, 3500);
      }

      function setPackageBusy(busy) {
        packageSubmitButton.disabled = busy;
        resetPackageButton.disabled = busy;
        packageSubmitButton.textContent = busy ? "上传中..." : "发布安装包";
      }

      function updateUploadProgress(percent, message) {
        var normalized = Math.max(0, Math.min(100, Math.round(percent)));
        uploadProgress.className = "upload-progress show";
        uploadProgressBar.style.width = normalized + "%";
        uploadProgressText.textContent = message || ("正在上传 " + normalized + "%");
      }

      function resetUploadProgress() {
        uploadProgress.className = "upload-progress";
        uploadProgressBar.style.width = "0%";
        uploadProgressText.textContent = "等待上传";
      }

      function setVersionEditBusy(busy) {
        versionEditBusy = busy;
        versionEditSubmit.disabled = busy;
        versionEditClose.disabled = busy;
        versionEditCancel.disabled = busy;
        versionEditForm.querySelector('[name="artifact"]').disabled = busy;
        versionEditSubmit.textContent = busy ? "保存中..." : "保存修改";
      }

      function updateVersionEditProgress(percent, message) {
        var normalized = Math.max(0, Math.min(100, Math.round(percent)));
        versionEditProgress.className = "upload-progress show";
        versionEditProgressBar.style.width = normalized + "%";
        versionEditProgressText.textContent = message || ("正在上传 " + normalized + "%");
      }

      function resetVersionEditProgress() {
        versionEditProgress.className = "upload-progress";
        versionEditProgressBar.style.width = "0%";
        versionEditProgressText.textContent = "等待保存";
      }

      async function request(path, options) {
        var headers = options && options.headers ? options.headers : {};
        headers.Accept = "application/json";
        headers.Authorization = token();
        var response = await fetch(apiBase + path, Object.assign({}, options, { headers: headers }));
        var payload = await response.json();
        if (!response.ok || payload.status === "fail") {
          throw new Error(payload.message || "请求失败");
        }
        return payload;
      }

      function uploadRequest(path, data, onProgress) {
        return new Promise(function (resolve, reject) {
          var xhr = new XMLHttpRequest();
          xhr.open("POST", apiBase + path);
          xhr.setRequestHeader("Accept", "application/json");
          xhr.setRequestHeader("Authorization", token());

          xhr.upload.onprogress = function (event) {
            if (!event.lengthComputable) {
              onProgress(null);
              return;
            }
            onProgress(event.loaded / event.total * 100);
          };

          xhr.onload = function () {
            var payload;
            try {
              payload = JSON.parse(xhr.responseText || "{}");
            } catch (e) {
              if (xhr.status === 413) {
                reject(new Error("安装包超过服务器上传大小限制，请调整网关上传限制后重试"));
                return;
              }
              reject(new Error("服务器返回了非 JSON 响应，HTTP " + xhr.status));
              return;
            }
            if (xhr.status < 200 || xhr.status >= 300 || payload.status === "fail") {
              reject(new Error(payload.message || "上传失败"));
              return;
            }
            resolve(payload);
          };

          xhr.onerror = function () {
            reject(new Error("上传失败，请检查网络后重试"));
          };

          xhr.onabort = function () {
            reject(new Error("上传已取消"));
          };

          xhr.send(data);
        });
      }

      function formDataToObject(form) {
        var data = {};
        Array.prototype.forEach.call(new FormData(form).entries(), function (entry) {
          data[entry[0]] = entry[1];
        });
        form.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
          data[input.name] = input.checked ? 1 : 0;
        });
        return data;
      }

      function defaultVersion() {
        var now = new Date();
        var month = String(now.getMonth() + 1).padStart(2, "0");
        var day = String(now.getDate()).padStart(2, "0");
        return now.getFullYear() + "." + month + "." + day;
      }

      function setPackageDefaults(force) {
        if (force) {
          packageForm.querySelector('[name="app_id"]').value = "";
          packageForm.querySelector('[name="app_key"]').value = "";
        }
        if (force || !packageForm.querySelector('[name="build_number"]').value) {
          packageForm.querySelector('[name="channel"]').value = "stable";
          packageForm.querySelector('[name="arch"]').value = "";
          packageForm.querySelector('[name="version"]').value = defaultVersion();
          packageForm.querySelector('[name="build_number"]').value = Math.floor(Date.now() / 1000);
          packageForm.querySelector('[name="min_supported_build"]').value = 0;
          packageForm.querySelector('[name="is_force"]').value = 0;
          packageForm.querySelector('[name="is_enabled"]').value = 1;
        }
        syncAppIdentityControls();
      }

      function stripKnownExtension(filename) {
        return String(filename || "")
          .replace(/\.(tar\.gz|tar\.xz|tar\.bz2)$/i, "")
          .replace(/\.[^.]+$/i, "");
      }

      function detectPlatform(filename) {
        var lower = String(filename || "").toLowerCase();
        if (/\.(apk|aab)$/i.test(lower) || /(^|[^a-z])android([^a-z]|$)/i.test(lower)) {
          return "android";
        }
        if (/\.(dmg|pkg)$/i.test(lower) || /(^|[^a-z])(macos|mac|darwin|osx)([^a-z]|$)/i.test(lower)) {
          return "macos";
        }
        if (/\.(exe|msi|msix|appx)$/i.test(lower) || /(^|[^a-z])(windows|win32|win64|win)([^a-z]|$)/i.test(lower)) {
          return "windows";
        }
        if (/\.ipa$/i.test(lower) || /(^|[^a-z])ios([^a-z]|$)/i.test(lower)) {
          return "ios";
        }
        if (/\.(appimage|deb|rpm)$/i.test(lower) || /(^|[^a-z])linux([^a-z]|$)/i.test(lower)) {
          return "linux";
        }
        return "";
      }

      function inferVersion(filename) {
        var base = stripKnownExtension(filename);
        var match = base.match(/(?:^|[-_\s])v?(\d+(?:\.\d+){1,4}(?:[+-][a-z0-9._-]+)?)(?=$|[-_\s])/i);
        return match ? match[1] : "";
      }

      function slugifyAppKey(value) {
        return String(value || "")
          .trim()
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, "-")
          .replace(/^-+|-+$/g, "")
          .replace(/-{2,}/g, "-");
      }

      function officialAppKeyForPlatform(platform) {
        return {
          android: "elephant-route-android",
          windows: "elephant-route-desktop",
          macos: "elephant-route-desktop"
        }[String(platform || "").toLowerCase()] || "";
      }

      function officialAppNameForPlatform(platform) {
        return {
          android: "大象网络官方App安卓版",
          windows: "大象网络官方App桌面版",
          macos: "大象网络官方App桌面版"
        }[String(platform || "").toLowerCase()] || "";
      }

      function inferAppKey(filename, appName, platform, distributionScope) {
        if (distributionScope === "official_update") {
          return officialAppKeyForPlatform(platform);
        }
        return slugifyAppKey(appName);
      }

      function syncAppIdentityControls() {
        var scopeInput = packageForm.querySelector('[name="distribution_scope"]');
        var platformInput = packageForm.querySelector('[name="platform"]');
        var appNameInput = packageForm.querySelector('[name="app_name"]');
        var appKeyInput = packageForm.querySelector('[name="app_key"]');
        var appIdInput = packageForm.querySelector('[name="app_id"]');
        var isOfficial = scopeInput.value === "official_update";

        appNameInput.readOnly = isOfficial;
        appKeyInput.readOnly = isOfficial;
        if (isOfficial) {
          appNameInput.value = officialAppNameForPlatform(platformInput.value);
          appKeyInput.value = officialAppKeyForPlatform(platformInput.value);
        } else if (!appKeyInput.value
          || officialAppKeyForPlatform("android") === appKeyInput.value
          || officialAppKeyForPlatform("windows") === appKeyInput.value
          || officialAppKeyForPlatform("macos") === appKeyInput.value) {
          appKeyInput.value = slugifyAppKey(appNameInput.value);
        }

        var currentApp = appIdInput.value ? findExistingAppById(appIdInput.value) : null;
        if (currentApp
          && (String(currentApp.distribution_scope || "download_only") !== scopeInput.value
            || String(currentApp.app_key || "").toLowerCase() !== appKeyInput.value.toLowerCase())) {
          appIdInput.value = "";
        }
      }

      function titleCase(words) {
        return words.map(function (word) {
          if (!word) {
            return "";
          }
          if (/^[A-Z0-9]+$/.test(word)) {
            return word;
          }
          return word.charAt(0).toUpperCase() + word.slice(1);
        }).join(" ");
      }

      function inferAppName(filename) {
        var base = stripKnownExtension(filename)
          .replace(/[_-]+/g, " ")
          .replace(/\s+/g, " ")
          .trim();
        var parts = base.split(" ").filter(Boolean);
        var ignored = [
          "android", "windows", "window", "win", "win32", "win64", "macos", "mac", "darwin", "osx", "ios", "linux",
          "setup", "installer", "install", "client", "desktop", "release", "stable", "beta",
          "x64", "x86", "x86_64", "amd64", "arm64", "aarch64", "armv7", "universal", "universal2",
          "signed", "unsigned"
        ];
        var kept = [];
        for (var i = 0; i < parts.length; i += 1) {
          var part = parts[i];
          var lower = part.toLowerCase();
          if (ignored.indexOf(lower) !== -1) {
            continue;
          }
          if (/^v?\d+(\.\d+){1,4}([+-].*)?$/i.test(part) || /^\d{6,}$/.test(part)) {
            continue;
          }
          kept.push(part);
        }
        return titleCase(kept.length ? kept : parts).trim();
      }

      function hasLegacyDecimalSpacing(existingName, inferredName) {
        var existing = String(existingName || "").trim();
        var inferred = String(inferredName || "").trim();
        return !!existing && !!inferred && existing !== inferred && inferred.replace(/\./g, " ") === existing;
      }

      function displayAppName(version) {
        var appName = version && version.app ? version.app.name : "";
        var artifactName = version && version.artifact ? version.artifact.original_name : "";
        var inferredName = artifactName ? inferAppName(artifactName) : "";
        return hasLegacyDecimalSpacing(appName, inferredName) ? inferredName : appName;
      }

      function escapeHtml(value) {
        return String(value == null ? "" : value).replace(/[&<>"']/g, function (char) {
          return {
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#39;"
          }[char];
        });
      }

      function formatAppIdentity(version) {
        var app = version && version.app ? version.app : {};
        var appKey = app.app_key || "";
        var label = displayAppName(version) || appKey || "-";
        return escapeHtml(label)
          + (appKey ? "<br><span class=\"muted\">" + escapeHtml(appKey) + "</span>" : "");
      }

      function formatVersionLabel(version) {
        var versionName = version && version.version ? version.version : "-";
        var buildNumber = version && version.build_number ? version.build_number : "";
        return escapeHtml(versionName)
          + (buildNumber ? "<br><span class=\"muted\">Build " + escapeHtml(buildNumber) + "</span>" : "");
      }

      function formatFileSize(bytes) {
        if (!bytes) {
          return "未知大小";
        }
        var megabytes = Number(bytes) / 1024 / 1024;
        return (Math.round(megabytes * 10) / 10) + " MB";
      }

      function formatDistributionScope(scope) {
        return String(scope || "download_only") === "official_update"
          ? "大象官方 App（支持自动更新）"
          : "第三方 App（仅供下载）";
      }

      function openVersionEditor(version) {
        var artifact = version.artifact;
        versionEditForm.reset();
        versionEditForm.querySelector('[name="id"]').value = version.id;
        versionEditForm.querySelector('[name="version"]').value = version.version || "";
        versionEditForm.querySelector('[name="release_notes"]').value = version.release_notes || "";
        versionEditIdentity.textContent = (displayAppName(version)
          || (version.app && version.app.app_key)
          || "-")
          + " / " + (version.platform || "-");
        versionEditAppType.textContent = formatDistributionScope(
          version.app && version.app.distribution_scope
        );
        versionEditCurrentArtifact.textContent = artifact
          ? artifact.original_name + "（" + formatFileSize(artifact.file_size) + "）"
          : "未上传";
        resetVersionEditProgress();
        setVersionEditBusy(false);
        versionEditModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        versionEditForm.querySelector('[name="version"]').focus();
      }

      function closeVersionEditor() {
        if (versionEditBusy) {
          return;
        }
        versionEditModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        versionEditForm.reset();
        resetVersionEditProgress();
      }

      function findExistingAppByGuess(name, distributionScope) {
        var normalized = String(name || "").trim().toLowerCase();
        var compact = normalized.replace(/[^a-z0-9]+/g, "");
        return apps.find(function (app) {
          var appName = String(app.name || "").trim().toLowerCase();
          var appKey = String(app.app_key || "").trim().toLowerCase();
          var appScope = String(app.distribution_scope || "download_only");
          return appScope === distributionScope
            && (appName === normalized
              || appKey === normalized.replace(/\s+/g, "-")
              || appName.replace(/[^a-z0-9]+/g, "") === compact
              || appKey.replace(/[^a-z0-9]+/g, "") === compact);
        });
      }

      function autofillPackageFromArtifact() {
        var file = artifactInput.files && artifactInput.files[0];
        if (!file) {
          return;
        }
        var guessedName = inferAppName(file.name);
        var guessedPlatform = detectPlatform(file.name);
        var guessedVersion = inferVersion(file.name);
        var distributionScope = packageForm.querySelector('[name="distribution_scope"]').value;
        var inferredAppKey = inferAppKey(file.name, guessedName, guessedPlatform, distributionScope);
        var existingApp = findExistingAppByKey(inferredAppKey, distributionScope)
          || findExistingAppByGuess(guessedName, distributionScope);
        if (guessedPlatform) {
          packageForm.querySelector('[name="platform"]').value = guessedPlatform;
        }
        packageForm.querySelector('[name="app_id"]').value = existingApp ? existingApp.id : "";
        packageForm.querySelector('[name="app_name"]').value = existingApp && !hasLegacyDecimalSpacing(existingApp.name, guessedName)
          ? existingApp.name
          : guessedName;
        packageForm.querySelector('[name="app_key"]').value = existingApp
          ? existingApp.app_key
          : inferredAppKey;
        if (guessedVersion) {
          packageForm.querySelector('[name="version"]').value = guessedVersion;
        }
        syncAppIdentityControls();
      }

      async function loadApps() {
        var payload = await request("/apps");
        apps = payload.data || [];
        setPackageDefaults(false);
      }

      async function loadDownloadSettings() {
        var payload = await request("/settings");
        var settings = payload.data || {};
        downloadSettingsForm.querySelector('[name="app_download_turnstile_enable"]').checked = !!settings.app_download_turnstile_enable;
        downloadSettingsForm.querySelector('[name="app_download_turnstile_site_key"]').value = settings.app_download_turnstile_site_key || "";
        downloadSettingsForm.querySelector('[name="app_download_turnstile_secret_key"]').value = settings.app_download_turnstile_secret_key || "";
        downloadSettingsHint.textContent = settings.uses_global_turnstile_fallback
          ? "当前未填写下载专用 key 时，会临时回退使用系统安全页里的全局 Turnstile key。"
          : "当前下载页使用下载专用 Turnstile key。";
      }

      async function loadVersions() {
        var payload = await request("/versions?per_page=100");
        versions = payload.data || [];
        renderVersions();
      }

      function renderVersions() {
        versionRows.innerHTML = "";
        versions.forEach(function (version) {
          var artifact = version.artifact;
          var row = document.createElement("tr");
          row.innerHTML = [
            "<td></td>",
            "<td></td>",
            "<td></td>",
            "<td></td>",
            "<td></td>",
            "<td></td>",
            '<td><div class="row"></div></td>'
          ].join("");
          row.children[0].innerHTML = formatAppIdentity(version);
          row.children[1].innerHTML = formatVersionLabel(version);
          row.children[2].textContent = version.platform;
          row.children[3].innerHTML = artifact
            ? artifact.original_name + "<br><span class=\"muted\">" + Math.round(artifact.file_size / 1024 / 1024 * 10) / 10 + " MB</span>"
            : '<span class="badge off">未上传</span>';
          var downloadCount = artifact ? Number(artifact.download_logs_count || 0) : 0;
          row.children[4].textContent = downloadCount.toLocaleString("zh-CN");
          row.children[5].innerHTML = version.is_enabled
            ? '<span class="badge ok">published</span>'
            : '<span class="badge off">disabled</span>';

          var actions = row.querySelector(".row");
          actions.appendChild(actionButton("编辑", function () { openVersionEditor(version); }));
          if (version.is_enabled) {
            actions.appendChild(actionButton("下架", function () { mutate("/versions/disable", { id: version.id }); }, "warn"));
          } else {
            actions.appendChild(actionButton("上架", function () { mutate("/versions/publish", { id: version.id }); }, "primary"));
          }
          actions.appendChild(actionButton("删除", function () {
            if (confirm("确认删除该版本和安装包文件？")) {
              mutate("/versions/drop", { id: version.id });
            }
          }, "danger"));
          versionRows.appendChild(row);
        });
      }

      function actionButton(text, onClick, className) {
        var button = document.createElement("button");
        button.type = "button";
        button.textContent = text;
        if (className) {
          button.className = className;
        }
        button.addEventListener("click", onClick);
        return button;
      }

      async function mutate(path, data) {
        await request(path, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(data)
        });
        showStatus("操作成功", true);
        await refreshAll();
      }

      async function refreshAll() {
        await loadDownloadSettings();
        await loadApps();
        await loadVersions();
      }

      downloadSettingsForm.addEventListener("submit", async function (event) {
        event.preventDefault();
        try {
          await mutate("/settings", formDataToObject(downloadSettingsForm));
          await loadDownloadSettings();
        } catch (e) {
          showStatus(e.message, false);
        }
      });

      versionEditForm.addEventListener("submit", async function (event) {
        event.preventDefault();
        var replacementInput = versionEditForm.querySelector('[name="artifact"]');
        var replacementFile = replacementInput.files && replacementInput.files[0];
        if (replacementFile && !confirm("保存后将立即切换到新安装包并删除旧文件，确认继续？")) {
          return;
        }

        setVersionEditBusy(true);
        updateVersionEditProgress(0, replacementFile ? "准备上传新安装包..." : "正在保存版本信息...");
        try {
          var data = new FormData(versionEditForm);
          await uploadRequest("/versions/update", data, function (percent) {
            if (percent === null) {
              updateVersionEditProgress(5, replacementFile ? "正在上传新安装包..." : "正在保存...");
              return;
            }
            var capped = percent >= 100 ? 99 : percent;
            updateVersionEditProgress(capped, replacementFile
              ? "正在上传 " + Math.round(capped) + "%"
              : "正在保存...");
          });
          updateVersionEditProgress(100, replacementFile ? "安装包已替换" : "版本信息已更新");
          setVersionEditBusy(false);
          closeVersionEditor();
          showStatus(replacementFile ? "版本和安装包已更新" : "版本信息已更新", true);
          await loadVersions();
        } catch (e) {
          showStatus(e.message, false);
          updateVersionEditProgress(0, e.message);
        } finally {
          setVersionEditBusy(false);
        }
      });

      function findExistingAppByName(name, distributionScope) {
        var normalized = String(name || "").trim().toLowerCase();
        return apps.find(function (app) {
          return String(app.name || "").trim().toLowerCase() === normalized
            && (!distributionScope
              || String(app.distribution_scope || "download_only") === distributionScope);
        });
      }

      function findExistingAppByKey(appKey, distributionScope) {
        var normalized = String(appKey || "").trim().toLowerCase();
        if (!normalized) {
          return null;
        }
        return apps.find(function (app) {
          return String(app.app_key || "").trim().toLowerCase() === normalized
            && (!distributionScope
              || String(app.distribution_scope || "download_only") === distributionScope);
        });
      }

      function findExistingAppById(id) {
        return apps.find(function (app) {
          return String(app.id) === String(id);
        });
      }

      artifactInput.addEventListener("change", function () {
        resetUploadProgress();
        autofillPackageFromArtifact();
      });
      packageForm.querySelector('[name="distribution_scope"]').addEventListener("change", syncAppIdentityControls);
      packageForm.querySelector('[name="platform"]').addEventListener("change", syncAppIdentityControls);

      packageForm.addEventListener("submit", async function (event) {
        event.preventDefault();
        setPackageBusy(true);
        updateUploadProgress(0, "准备发布安装包...");
        try {
          setPackageDefaults(false);
          var appName = packageForm.querySelector('[name="app_name"]').value.trim();
          var appKey = packageForm.querySelector('[name="app_key"]').value.trim();
          var distributionScope = packageForm.querySelector('[name="distribution_scope"]').value;
          var existingApp = findExistingAppByKey(appKey, distributionScope)
            || (distributionScope === "download_only"
              ? findExistingAppByName(appName, distributionScope)
              : null);
          var currentAppId = packageForm.querySelector('[name="app_id"]').value;
          var currentApp = currentAppId ? findExistingAppById(currentAppId) : null;
          if (currentApp
            && String(currentApp.name || "").trim().toLowerCase() !== appName.toLowerCase()
            && String(currentApp.app_key || "").trim().toLowerCase() !== appKey.toLowerCase()) {
            currentAppId = "";
            packageForm.querySelector('[name="app_id"]').value = "";
          }
          var appPayload = {
            name: appName,
            app_key: appKey,
            platform: packageForm.querySelector('[name="platform"]').value,
            distribution_scope: distributionScope,
            description: packageForm.querySelector('[name="release_notes"]').value || "",
            is_active: 1
          };
          if (currentAppId || existingApp) {
            appPayload.id = currentAppId || existingApp.id;
          }
          var appResponse = await request("/apps/save", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(appPayload)
          });
          var app = appResponse.data || {};
          updateUploadProgress(1, "应用信息已保存，开始上传安装包...");

          var data = new FormData(packageForm);
          data.set("app_id", app.id);
          data.delete("id");
          data.delete("app_name");
          data.delete("app_key");
          await uploadRequest("/versions/save", data, function (percent) {
            if (percent === null) {
              updateUploadProgress(5, "正在上传安装包...");
              return;
            }
            var capped = percent >= 100 ? 99 : percent;
            updateUploadProgress(capped, "正在上传 " + Math.round(capped) + "%");
          });
          updateUploadProgress(100, "上传完成，版本已发布");
          showStatus("安装包已发布", true);
          packageForm.reset();
          setPackageDefaults(true);
          syncAppIdentityControls();
          await refreshAll();
        } catch (e) {
          showStatus(e.message, false);
          updateUploadProgress(0, e.message);
        } finally {
          setPackageBusy(false);
        }
      });

      resetPackageButton.addEventListener("click", function () {
        packageForm.reset();
        packageForm.querySelector('[name="app_id"]').value = "";
        resetUploadProgress();
        setPackageDefaults(true);
        syncAppIdentityControls();
      });
      versionEditClose.addEventListener("click", closeVersionEditor);
      versionEditCancel.addEventListener("click", closeVersionEditor);
      versionEditModal.addEventListener("click", function (event) {
        if (event.target === versionEditModal) {
          closeVersionEditor();
        }
      });
      document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && versionEditModal.getAttribute("aria-hidden") === "false") {
          closeVersionEditor();
        }
      });
      document.getElementById("refresh").addEventListener("click", function () {
        refreshAll().catch(function (e) { showStatus(e.message, false); });
      });

      if (!token()) {
        showStatus("未读取到管理员登录 token，请先从 Xboard 后台登录。", false);
      } else {
        refreshAll().catch(function (e) { showStatus(e.message, false); });
      }
    })();
  </script>
</body>
</html>
