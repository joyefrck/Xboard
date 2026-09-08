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
      <p class="muted">管理应用、下载链接发布、下架和删除。</p>
    </div>
    <a class="link-button" href="/{{ $secure_path }}">返回后台</a>
  </header>

  <div id="status"></div>

  <main class="grid">
    <section class="card">
      <h2>发布下载链接</h2>
      <p class="muted">填写第三方 HTTPS 下载链接，提交后会自动创建或复用应用并发布版本。</p>
      <form id="package-form">
        <input type="hidden" name="app_id">
        <input type="hidden" name="channel" value="stable">
        <input type="hidden" name="arch">
        <input type="hidden" name="min_supported_build" value="0">
        <input type="hidden" name="is_force" value="0">
        <input type="hidden" name="is_enabled" value="1">
        <label>下载链接<input name="download_url" type="url" required maxlength="2048" placeholder="https://file.example.com/d?id=example"></label>
        <label>安装包大小（MB，可选）<input name="file_size_mb" type="number" min="0" step="0.01" placeholder="例如 85.5"></label>
        <label>SHA256（macOS 官方更新必填）<input name="sha256" maxlength="64" pattern="[a-fA-F0-9]{64}" placeholder="64 位十六进制校验值"></label>
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
        <label>构建号<input name="build_number" type="number" min="1" step="1" required placeholder="例如 20006"></label>
        <p class="muted" id="release-metadata-help">第三方 App 可使用系统生成的目录版本；大象官方 App 的版本号和构建号必须与安装包一致。</p>
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
          <button class="primary" type="submit">发布下载链接</button>
          <button type="button" id="reset-package">清空</button>
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
              <th>下载链接</th>
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
        <div><strong>当前下载链接：</strong><span id="version-edit-current-url">-</span></div>
      </div>
      <form id="version-edit-form">
        <input type="hidden" name="id">
        <label>版本号<input name="version" required maxlength="32"></label>
        <label>发布说明<textarea name="release_notes" maxlength="20000" placeholder="用于公开下载页展示"></textarea></label>
        <label>下载链接<input name="download_url" type="url" required maxlength="2048"></label>
        <label>安装包大小（MB，可选）<input name="file_size_mb" type="number" min="0" step="0.01"></label>
        <label>SHA256（macOS 官方更新必填）<input name="sha256" maxlength="64" pattern="[a-fA-F0-9]{64}"></label>
        <p class="muted">修改后会立即更新公开下载信息。</p>
        <div class="row" style="margin-top:14px">
          <button class="primary" type="submit">保存修改</button>
          <button type="button" id="version-edit-cancel">取消</button>
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
      var packageSubmitButton = packageForm.querySelector('button[type="submit"]');
      var resetPackageButton = document.getElementById("reset-package");
      var versionRows = document.getElementById("version-rows");
      var versionEditModal = document.getElementById("version-edit-modal");
      var versionEditForm = document.getElementById("version-edit-form");
      var versionEditIdentity = document.getElementById("version-edit-identity");
      var versionEditAppType = document.getElementById("version-edit-app-type");
      var versionEditCurrentUrl = document.getElementById("version-edit-current-url");
      var versionEditClose = document.getElementById("version-edit-close");
      var versionEditCancel = document.getElementById("version-edit-cancel");
      var versionEditSubmit = versionEditForm.querySelector('button[type="submit"]');
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
        packageSubmitButton.textContent = busy ? "发布中..." : "发布下载链接";
      }

      function setVersionEditBusy(busy) {
        versionEditBusy = busy;
        versionEditSubmit.disabled = busy;
        versionEditClose.disabled = busy;
        versionEditCancel.disabled = busy;
        versionEditSubmit.textContent = busy ? "保存中..." : "保存修改";
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

      function applyGeneratedReleaseMetadata() {
        var versionInput = packageForm.querySelector('[name="version"]');
        var buildInput = packageForm.querySelector('[name="build_number"]');
        versionInput.value = defaultVersion();
        buildInput.value = Math.floor(Date.now() / 1000);
        versionInput.dataset.generatedDefault = "1";
        buildInput.dataset.generatedDefault = "1";
      }

      function syncReleaseMetadataControls() {
        var scopeInput = packageForm.querySelector('[name="distribution_scope"]');
        var versionInput = packageForm.querySelector('[name="version"]');
        var buildInput = packageForm.querySelector('[name="build_number"]');
        var isOfficial = scopeInput.value === "official_update";

        if (isOfficial) {
          if (versionInput.dataset.generatedDefault === "1") {
            versionInput.value = "";
          }
          if (buildInput.dataset.generatedDefault === "1") {
            buildInput.value = "";
          }
          versionInput.dataset.generatedDefault = "0";
          buildInput.dataset.generatedDefault = "0";
        } else if (!versionInput.value && !buildInput.value) {
          applyGeneratedReleaseMetadata();
        }
      }

      function setPackageDefaults(force) {
        if (force) {
          packageForm.querySelector('[name="app_id"]').value = "";
          packageForm.querySelector('[name="app_key"]').value = "";
          packageForm.querySelector('[name="version"]').dataset.generatedDefault = "0";
          packageForm.querySelector('[name="build_number"]').dataset.generatedDefault = "0";
        }
        if (force || !packageForm.querySelector('[name="channel"]').value) {
          packageForm.querySelector('[name="channel"]').value = "stable";
          packageForm.querySelector('[name="arch"]').value = "";
          packageForm.querySelector('[name="min_supported_build"]').value = 0;
          packageForm.querySelector('[name="is_force"]').value = 0;
          packageForm.querySelector('[name="is_enabled"]').value = 1;
        }
        syncReleaseMetadataControls();
        syncAppIdentityControls();
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

      function displayAppName(version) {
        return version && version.app ? version.app.name : "";
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

      function formatDistributionScope(scope) {
        return String(scope || "download_only") === "official_update"
          ? "大象官方 App（支持自动更新）"
          : "第三方 App（仅供下载）";
      }

      function openVersionEditor(version) {
        versionEditForm.reset();
        versionEditForm.querySelector('[name="id"]').value = version.id;
        versionEditForm.querySelector('[name="version"]').value = version.version || "";
        versionEditForm.querySelector('[name="release_notes"]').value = version.release_notes || "";
        versionEditForm.querySelector('[name="download_url"]').value = version.download_url || "";
        versionEditForm.querySelector('[name="file_size_mb"]').value = version.file_size
          ? Math.round(Number(version.file_size) / 1024 / 1024 * 100) / 100
          : "";
        versionEditForm.querySelector('[name="sha256"]').value = version.sha256 || "";
        versionEditIdentity.textContent = (displayAppName(version)
          || (version.app && version.app.app_key)
          || "-")
          + " / " + (version.platform || "-");
        versionEditAppType.textContent = formatDistributionScope(
          version.app && version.app.distribution_scope
        );
        versionEditCurrentUrl.textContent = version.download_url || "未配置";
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
      }

      async function loadApps() {
        var payload = await request("/apps");
        apps = payload.data || [];
        setPackageDefaults(false);
      }

      async function loadVersions() {
        var payload = await request("/versions?per_page=100");
        versions = payload.data || [];
        renderVersions();
      }

      function renderVersions() {
        versionRows.innerHTML = "";
        versions.forEach(function (version) {
          var row = document.createElement("tr");
          row.innerHTML = [
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
          if (version.download_url) {
            var downloadLink = document.createElement("a");
            downloadLink.href = version.download_url;
            downloadLink.target = "_blank";
            downloadLink.rel = "noopener noreferrer";
            downloadLink.textContent = version.download_url.length > 48
              ? version.download_url.slice(0, 45) + "..."
              : version.download_url;
            downloadLink.title = version.download_url;
            row.children[3].appendChild(downloadLink);
            if (version.file_size) {
              var size = document.createElement("span");
              size.className = "muted";
              size.textContent = " " + (Math.round(Number(version.file_size) / 1024 / 1024 * 10) / 10) + " MB";
              row.children[3].appendChild(document.createElement("br"));
              row.children[3].appendChild(size);
            }
          } else {
            row.children[3].innerHTML = '<span class="badge off">未配置</span>';
          }
          row.children[4].innerHTML = version.is_enabled
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
            if (confirm("确认删除该版本？关联的历史本地文件也会一并清理。")) {
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
        await loadApps();
        await loadVersions();
      }

      versionEditForm.addEventListener("submit", async function (event) {
        event.preventDefault();
        setVersionEditBusy(true);
        try {
          var editPayload = {
            id: versionEditForm.querySelector('[name="id"]').value,
            version: versionEditForm.querySelector('[name="version"]').value,
            release_notes: versionEditForm.querySelector('[name="release_notes"]').value,
            download_url: versionEditForm.querySelector('[name="download_url"]').value,
            file_size_mb: versionEditForm.querySelector('[name="file_size_mb"]').value,
            sha256: versionEditForm.querySelector('[name="sha256"]').value
          };
          await request("/versions/update", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(editPayload)
          });
          setVersionEditBusy(false);
          closeVersionEditor();
          showStatus("版本信息已更新", true);
          await loadVersions();
        } catch (e) {
          showStatus(e.message, false);
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

      packageForm.querySelector('[name="distribution_scope"]').addEventListener("change", function () {
        syncAppIdentityControls();
        syncReleaseMetadataControls();
      });
      packageForm.querySelector('[name="platform"]').addEventListener("change", syncAppIdentityControls);
      packageForm.querySelector('[name="version"]').addEventListener("input", function (event) {
        event.target.dataset.generatedDefault = "0";
      });
      packageForm.querySelector('[name="build_number"]').addEventListener("input", function (event) {
        event.target.dataset.generatedDefault = "0";
      });

      packageForm.addEventListener("submit", async function (event) {
        event.preventDefault();
        setPackageBusy(true);
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
          var data = {
            app_id: app.id,
            platform: packageForm.querySelector('[name="platform"]').value,
            channel: packageForm.querySelector('[name="channel"]').value,
            arch: packageForm.querySelector('[name="arch"]').value,
            version: packageForm.querySelector('[name="version"]').value,
            build_number: packageForm.querySelector('[name="build_number"]').value,
            min_supported_build: packageForm.querySelector('[name="min_supported_build"]').value,
            release_notes: packageForm.querySelector('[name="release_notes"]').value,
            is_force: packageForm.querySelector('[name="is_force"]').value,
            is_enabled: packageForm.querySelector('[name="is_enabled"]').value,
            download_url: packageForm.querySelector('[name="download_url"]').value,
            file_size_mb: packageForm.querySelector('[name="file_size_mb"]').value,
            sha256: packageForm.querySelector('[name="sha256"]').value
          };
          await request("/versions/save", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data)
          });
          showStatus("下载链接已发布", true);
          packageForm.reset();
          setPackageDefaults(true);
          syncAppIdentityControls();
          await refreshAll();
        } catch (e) {
          showStatus(e.message, false);
        } finally {
          setPackageBusy(false);
        }
      });

      resetPackageButton.addEventListener("click", function () {
        packageForm.reset();
        packageForm.querySelector('[name="app_id"]').value = "";
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
