<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }}</title>
  <script>
    window.settings = {
      base_url: "/",
      title: "{{ $title }}",
      version: "{{ $version }}",
      logo: "{{ $logo }}",
      secure_path: "{{ $secure_path }}",
    };
  </script>
  <script type="module" crossorigin src="/assets/admin/assets/index.js?v=20260902-1"></script>
  <link rel="stylesheet" crossorigin href="/assets/admin/assets/index.css" />
  <link rel="stylesheet" crossorigin href="/assets/admin/assets/vendor.css">
  <script src="/assets/admin/locales/en-US.js"></script>
  <script src="/assets/admin/locales/zh-CN.js"></script>
  <script src="/assets/admin/locales/ko-KR.js"></script>
  <style>
    .xboard-app-downloads-entry {
      position: fixed;
      right: 18px;
      bottom: 18px;
      z-index: 2147483000;
      display: none;
      align-items: center;
      justify-content: center;
      min-height: 36px;
      border: 1px solid rgba(15, 23, 42, 0.12);
      border-radius: 6px;
      padding: 0 12px;
      color: #fff;
      background: #0f172a;
      box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18);
      font: 500 13px/1.2 Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      text-decoration: none;
    }
    .xboard-app-downloads-entry:hover {
      background: #1e293b;
    }
    .xboard-app-downloads-entry.is-visible {
      display: inline-flex;
    }
    .xboard-admin-ticket-row-click {
      cursor: pointer;
    }
    .rc-md-editor.xboard-knowledge-image-dragover {
      outline: 2px dashed hsl(var(--primary));
      outline-offset: 2px;
    }
    .rc-md-editor.xboard-knowledge-image-uploading .button-type-image {
      cursor: wait;
      opacity: 0.55;
      pointer-events: none;
    }
  </style>
</head>

<body>
  <div id="root"></div>
  <a class="xboard-app-downloads-entry" id="xboard-app-downloads-entry" href="/{{ $secure_path }}/app-downloads">App 下载管理</a>
  <script>
    (function () {
      var entry = document.getElementById("xboard-app-downloads-entry");
      if (!entry) return;

      function hasAdminToken() {
        return !!(
          localStorage.getItem("authorization") ||
          localStorage.getItem("XBOARD_ACCESS_TOKEN") ||
          localStorage.getItem("access_token")
        );
      }

      function isAuthPage() {
        return /^#\/?(sign-in|sign-in-2|sign-up|forgot-password|otp|login|register|forget)(?:[/?#]|$)/.test(window.location.hash || "");
      }

      function syncEntryVisibility() {
        entry.classList.toggle("is-visible", hasAdminToken() && !isAuthPage());
      }

      syncEntryVisibility();
      window.addEventListener("storage", syncEntryVisibility);
      window.addEventListener("focus", syncEntryVisibility);
      window.addEventListener("hashchange", syncEntryVisibility);
      window.addEventListener("pageshow", syncEntryVisibility);
      setInterval(syncEntryVisibility, 500);
    })();
  </script>
  <script>
    (function () {
      var VIEW_DETAIL_TITLES = ["查看详情", "View Details", "상세 보기"];

      function isTicketRoute() {
        return /^#\/?user\/ticket(?:[/?#]|$)/.test(window.location.hash || "");
      }

      function isInteractiveTarget(target) {
        if (!(target instanceof Element)) return true;
        return !!target.closest('button, a, input, textarea, select, label, [role="button"], [role="menuitem"], [contenteditable="true"]');
      }

      function findTicketViewButton(row) {
        var buttons = row.querySelectorAll("button[title]");
        for (var index = 0; index < buttons.length; index += 1) {
          if (VIEW_DETAIL_TITLES.indexOf(buttons[index].getAttribute("title")) !== -1) {
            return buttons[index];
          }
        }
        return null;
      }

      function closestTicketRow(target) {
        if (!isTicketRoute() || !(target instanceof Element)) return null;
        var row = target.closest("tbody tr");
        if (!row || !findTicketViewButton(row)) return null;
        return row;
      }

      document.addEventListener("pointerover", function (event) {
        var row = closestTicketRow(event.target);
        if (row) row.classList.add("xboard-admin-ticket-row-click");
      }, true);

      document.addEventListener("click", function (event) {
        if (isInteractiveTarget(event.target)) return;

        var row = closestTicketRow(event.target);
        if (!row) return;

        var viewButton = findTicketViewButton(row);
        if (!viewButton) return;

        event.preventDefault();
        viewButton.click();
      });
    })();
  </script>
  <script>
    (function () {
      var KNOWLEDGE_IMAGE_TARGET_BYTES = 1024 * 1024;
      var KNOWLEDGE_IMAGE_WEBP_QUALITIES = [0.82, 0.74, 0.65];
      var KNOWLEDGE_IMAGE_ACCEPT = "image/jpeg,image/png,image/gif,image/webp";
      var KNOWLEDGE_IMAGE_TYPES = KNOWLEDGE_IMAGE_ACCEPT.split(",");
      var knowledgeUploadBusy = false;
      var pendingImageContext = null;
      var pendingImageSelection = null;

      function isKnowledgeRoute() {
        return /^#\/?(?:config\/)?knowledge(?:[\/?#]|$)/.test(window.location.hash || "");
      }

      function getAdminToken() {
        var token = (
          localStorage.getItem("XBOARD_ACCESS_TOKEN") ||
          localStorage.getItem("authorization") ||
          localStorage.getItem("access_token") ||
          ""
        );

        try {
          var parsedToken = JSON.parse(token);
          if (parsedToken && typeof parsedToken.value === "string") {
            return parsedToken.value;
          }
        } catch (error) {
          // Legacy token values are stored as plain strings.
        }

        return token;
      }

      function getKnowledgeEditorContext(target) {
        if (!isKnowledgeRoute() || !(target instanceof Element)) return null;

        var editor = target.closest(".rc-md-editor");
        if (!editor) return null;

        var textarea = editor.querySelector(".sec-md textarea");
        var imageButton = editor.querySelector(".button-type-image");
        if (!(textarea instanceof HTMLTextAreaElement) || !imageButton) return null;

        return {
          editor: editor,
          textarea: textarea,
          imageButton: imageButton
        };
      }

      function getClipboardFiles(event) {
        var items = event.clipboardData && event.clipboardData.items;
        if (!items) return [];

        var files = [];
        for (var index = 0; index < items.length; index += 1) {
          var item = items[index];
          if (item.kind === "file") {
            var file = item.getAsFile();
            if (file) files.push(file);
          }
        }
        return files;
      }

      function saveSelection(target) {
        if (target instanceof HTMLTextAreaElement) {
          return {
            type: "textarea",
            start: target.selectionStart,
            end: target.selectionEnd
          };
        }

        var selection = window.getSelection && window.getSelection();
        if (selection && selection.rangeCount > 0) {
          return {
            type: "range",
            range: selection.getRangeAt(0).cloneRange()
          };
        }

        return { type: "none" };
      }

      function restoreSelection(target, savedSelection) {
        target.focus();
        if (savedSelection.type === "textarea" && target instanceof HTMLTextAreaElement) {
          target.selectionStart = savedSelection.start;
          target.selectionEnd = savedSelection.end;
          return;
        }

        if (savedSelection.type === "range") {
          var selection = window.getSelection && window.getSelection();
          if (selection) {
            selection.removeAllRanges();
            selection.addRange(savedSelection.range);
          }
        }
      }

      function insertMarkdown(target, savedSelection, markdown) {
        if (!target || !target.isConnected) return false;
        restoreSelection(target, savedSelection);

        if (target instanceof HTMLTextAreaElement) {
          target.setRangeText(markdown, target.selectionStart, target.selectionEnd, "end");
          target.dispatchEvent(new Event("input", { bubbles: true }));
          target.dispatchEvent(new Event("change", { bubbles: true }));
          savedSelection.start = target.selectionStart;
          savedSelection.end = target.selectionEnd;
          return true;
        }

        document.execCommand("insertText", false, markdown);
        target.dispatchEvent(new Event("input", { bubbles: true }));
        savedSelection.range = window.getSelection().getRangeAt(0).cloneRange();
        return true;
      }

      function notifyUploadError(message) {
        window.alert(message || "图片上传失败，请稍后重试");
      }

      function notifyKnowledgeUploadFailures(failedFiles) {
        if (failedFiles.length === 0) return;

        var lines = failedFiles.map(function (failure) {
          return failure.name + "：" + failure.message;
        });
        notifyUploadError("以下图片未能插入：\n" + lines.join("\n"));
      }

      function isSupportedKnowledgeImage(file) {
        return !!file && KNOWLEDGE_IMAGE_TYPES.indexOf(file.type || "") !== -1;
      }

      function createKnowledgeImagePicker() {
        var knowledgeImageInput = document.createElement("input");
        knowledgeImageInput.type = "file";
        knowledgeImageInput.accept = KNOWLEDGE_IMAGE_ACCEPT;
        knowledgeImageInput.multiple = true;
        knowledgeImageInput.hidden = true;
        knowledgeImageInput.setAttribute("aria-hidden", "true");
        document.body.appendChild(knowledgeImageInput);
        return knowledgeImageInput;
      }

      function setKnowledgeUploadState(context, uploading) {
        if (!context || !context.editor || !context.imageButton) return;

        context.editor.classList.toggle("xboard-knowledge-image-uploading", uploading);
        context.editor.classList.remove("xboard-knowledge-image-dragover");
        context.imageButton.setAttribute("aria-busy", uploading ? "true" : "false");

        if (!context.imageButton.dataset.xboardOriginalTitle) {
          context.imageButton.dataset.xboardOriginalTitle = context.imageButton.getAttribute("title") || "图片";
        }
        context.imageButton.setAttribute(
          "title",
          uploading ? "图片上传中…" : context.imageButton.dataset.xboardOriginalTitle
        );
      }

      function canvasToBlob(canvas, mimeType, quality) {
        return new Promise(function (resolve) {
          canvas.toBlob(resolve, mimeType, quality);
        });
      }

      function encodeKnowledgeImage(canvas) {
        var smallestBlob = null;

        return KNOWLEDGE_IMAGE_WEBP_QUALITIES.reduce(function (chain, quality) {
          return chain.then(function (targetBlob) {
            if (targetBlob) return targetBlob;

            return canvasToBlob(canvas, "image/webp", quality).then(function (blob) {
              if (!blob) return null;
              if (!smallestBlob || blob.size < smallestBlob.size) {
                smallestBlob = blob;
              }
              return blob.size <= KNOWLEDGE_IMAGE_TARGET_BYTES ? blob : null;
            });
          });
        }, Promise.resolve(null)).then(function (targetBlob) {
          return targetBlob || smallestBlob;
        });
      }

      function compressKnowledgeImage(file) {
        if (!file || !/^image\//.test(file.type || "") || file.type === "image/gif") {
          return Promise.resolve(file);
        }

        if (!window.createImageBitmap || typeof File !== "function") {
          return Promise.resolve(file);
        }

        return createImageBitmap(file).then(function (bitmap) {
          var canvas = document.createElement("canvas");
          canvas.width = bitmap.width;
          canvas.height = bitmap.height;

          var context = canvas.getContext("2d");
          if (!context) {
            if (typeof bitmap.close === "function") bitmap.close();
            return file;
          }

          context.drawImage(bitmap, 0, 0, bitmap.width, bitmap.height);
          if (typeof bitmap.close === "function") bitmap.close();

          return encodeKnowledgeImage(canvas).then(function (blob) {
            if (!blob || blob.size >= file.size) return file;

            var baseName = file.name ? file.name.replace(/\.[^.]+$/, "") : "image";
            return new File([blob], baseName + ".webp", {
              type: "image/webp",
              lastModified: Date.now()
            });
          });
        }).catch(function () {
          return file;
        });
      }

      function uploadKnowledgeImage(file) {
        return compressKnowledgeImage(file).then(function (uploadFile) {
          var formData = new FormData();
          formData.append("file", uploadFile);

          return fetch("/api/v2/" + window.settings.secure_path + "/knowledge/upload-image", {
            method: "POST",
            headers: {
              Authorization: getAdminToken()
            },
            body: formData
          }).then(function (response) {
            return response.json().catch(function () {
              return null;
            }).then(function (result) {
              if (!response.ok || !result || !result.data || !result.data.url) {
                throw new Error((result && result.message) || ("图片上传失败（HTTP " + response.status + "）"));
              }
              return result.data.url;
            });
          });
        });
      }

      function queueKnowledgeImages(files, context, savedSelection) {
        var selectedFiles = Array.from(files || []);
        if (selectedFiles.length === 0 || !context || knowledgeUploadBusy) {
          return Promise.resolve();
        }

        var acceptedFiles = [];
        var failedFiles = [];
        selectedFiles.forEach(function (file) {
          if (isSupportedKnowledgeImage(file)) {
            acceptedFiles.push(file);
            return;
          }
          failedFiles.push({
            name: file.name || "未命名文件",
            message: "仅支持 jpg、jpeg、png、gif、webp 图片"
          });
        });

        if (acceptedFiles.length === 0) {
          notifyKnowledgeUploadFailures(failedFiles);
          return Promise.resolve();
        }

        knowledgeUploadBusy = true;
        setKnowledgeUploadState(context, true);
        var insertedCount = 0;

        return acceptedFiles.reduce(function (chain, file, index) {
          return chain.then(function () {
            return uploadKnowledgeImage(file).then(function (url) {
              var altText = file.name ? file.name.replace(/\.[^.]+$/, "") : "image";
              var markdown = (insertedCount > 0 ? "\n" : "") + `![${altText}](${url})`;
              if (!insertMarkdown(context.textarea, savedSelection, markdown)) {
                throw new Error("编辑器已关闭，图片未插入");
              }
              insertedCount += 1;
            }).catch(function (error) {
              failedFiles.push({
                name: file.name || ("图片 " + (index + 1)),
                message: (error && error.message) || "图片上传失败"
              });
            });
          });
        }, Promise.resolve()).then(function () {
          notifyKnowledgeUploadFailures(failedFiles);
        }).finally(function () {
          knowledgeUploadBusy = false;
          setKnowledgeUploadState(context, false);
        });
      }

      var knowledgeImageInput = createKnowledgeImagePicker();
      knowledgeImageInput.addEventListener("change", function () {
        var context = pendingImageContext;
        var savedSelection = pendingImageSelection;
        var files = Array.from(knowledgeImageInput.files || []);

        pendingImageContext = null;
        pendingImageSelection = null;
        knowledgeImageInput.value = "";

        if (!context || !savedSelection || files.length === 0) return;
        queueKnowledgeImages(files, context, savedSelection);
      });

      document.addEventListener("click", function (event) {
        if (!(event.target instanceof Element)) return;

        var imageButton = event.target.closest(".button-type-image");
        if (!imageButton) return;

        var context = getKnowledgeEditorContext(imageButton);
        if (!context) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        if (knowledgeUploadBusy) return;

        pendingImageContext = context;
        pendingImageSelection = saveSelection(context.textarea);
        knowledgeImageInput.value = "";
        knowledgeImageInput.click();
      }, true);

      document.addEventListener("paste", function (event) {
        var context = getKnowledgeEditorContext(event.target);
        if (!context || event.target !== context.textarea) return;

        var files = getClipboardFiles(event);
        if (files.length === 0) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        var savedSelection = saveSelection(context.textarea);
        queueKnowledgeImages(files, context, savedSelection);
      }, true);

      document.addEventListener("dragover", function (event) {
        var context = getKnowledgeEditorContext(event.target);
        if (!context || !event.dataTransfer) return;

        var types = event.dataTransfer.types || [];
        if (Array.prototype.indexOf.call(types, "Files") === -1) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        context.editor.classList.add("xboard-knowledge-image-dragover");
      }, true);

      document.addEventListener("dragleave", function (event) {
        var context = getKnowledgeEditorContext(event.target);
        if (!context) return;
        if (event.relatedTarget instanceof Node && context.editor.contains(event.relatedTarget)) return;

        context.editor.classList.remove("xboard-knowledge-image-dragover");
      }, true);

      document.addEventListener("drop", function (event) {
        var context = getKnowledgeEditorContext(event.target);
        if (!context || !event.dataTransfer || event.dataTransfer.files.length === 0) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        context.editor.classList.remove("xboard-knowledge-image-dragover");
        var savedSelection = saveSelection(context.textarea);
        var files = Array.from(event.dataTransfer.files);
        queueKnowledgeImages(files, context, savedSelection);
      }, true);
    })();
  </script>
</body>

</html>
