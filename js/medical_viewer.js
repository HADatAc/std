(function (Drupal, once, drupalSettings) {
  "use strict";

  const parseFirstNumber = function (rawValue, fallbackValue) {
    const text = String(rawValue || "").trim();
    if (!text) {
      return fallbackValue;
    }

    const first = text.split("\\")[0].trim();
    const parsed = Number(first);
    return Number.isFinite(parsed) ? parsed : fallbackValue;
  };

  const clamp = function (value, min, max) {
    return Math.min(max, Math.max(min, value));
  };

  const parseFrameCount = function (rawValue) {
    const parsed = parseFirstNumber(rawValue, 1);
    const normalized = Math.floor(Number(parsed));
    return Number.isFinite(normalized) && normalized > 0 ? normalized : 1;
  };

  const isCompressedTransferSyntax = function (transferSyntax) {
    const normalized = String(transferSyntax || "").trim();
    return normalized.indexOf("1.2.840.10008.1.2.4.") === 0 || normalized === "1.2.840.10008.1.2.5";
  };

  const isLittleEndianTransferSyntax = function (transferSyntax) {
    return String(transferSyntax || "").trim() !== "1.2.840.10008.1.2.2";
  };

  const readPixelValue = function (dataView, offset, bitsAllocated, pixelRepresentation, littleEndian) {
    if (bitsAllocated === 8) {
      return pixelRepresentation === 1 ? dataView.getInt8(offset) : dataView.getUint8(offset);
    }

    if (pixelRepresentation === 1) {
      return dataView.getInt16(offset, littleEndian);
    }

    return dataView.getUint16(offset, littleEndian);
  };

  const buildGrayscaleImageStack = function (dataSet) {
    const rows = Number(dataSet.uint16("x00280010") || 0);
    const columns = Number(dataSet.uint16("x00280011") || 0);
    const samplesPerPixel = Number(dataSet.uint16("x00280002") || 1);
    const bitsAllocated = Number(dataSet.uint16("x00280100") || 0);
    const pixelRepresentation = Number(dataSet.uint16("x00280103") || 0);
    const photometric = String(dataSet.string("x00280004") || "MONOCHROME2").toUpperCase();
    const transferSyntax = String(dataSet.string("x00020010") || "");

    if (!rows || !columns || rows <= 0 || columns <= 0) {
      throw new Error("Missing image dimensions.");
    }

    if (samplesPerPixel !== 1) {
      throw new Error("Only single-channel medical images are supported.");
    }

    if (bitsAllocated !== 8 && bitsAllocated !== 16) {
      throw new Error("Unsupported pixel depth.");
    }

    if (photometric !== "MONOCHROME1" && photometric !== "MONOCHROME2") {
      throw new Error("Unsupported photometric interpretation.");
    }

    if (isCompressedTransferSyntax(transferSyntax)) {
      throw new Error("Compressed transfer syntaxes are not supported.");
    }

    const pixelElement = dataSet.elements.x7fe00010;
    if (!pixelElement) {
      throw new Error("Pixel data element is missing.");
    }

    const bytesPerPixel = bitsAllocated / 8;
    const pixelCount = rows * columns;
    const singleFrameBytes = pixelCount * bytesPerPixel;
    const availableFrameCount = Math.floor(Number(pixelElement.length || 0) / singleFrameBytes);

    if (availableFrameCount < 1 || pixelElement.length < singleFrameBytes) {
      throw new Error("Pixel data is truncated.");
    }

    const declaredFrameCount = parseFrameCount(dataSet.string("x00280008"));
    const frameCount = Math.max(1, Math.min(declaredFrameCount, availableFrameCount));

    const slope = parseFirstNumber(dataSet.string("x00281053"), 1) || 1;
    const intercept = parseFirstNumber(dataSet.string("x00281052"), 0) || 0;
    const center = parseFirstNumber(dataSet.string("x00281050"), null);
    const width = parseFirstNumber(dataSet.string("x00281051"), null);
    const hasWindow = Number.isFinite(center) && Number.isFinite(width) && width > 1;

    const byteOffset = dataSet.byteArray.byteOffset + pixelElement.dataOffset;
    const dataByteLength = frameCount * singleFrameBytes;
    const dataView = new DataView(dataSet.byteArray.buffer, byteOffset, dataByteLength);
    const littleEndian = isLittleEndianTransferSyntax(transferSyntax);
    const frameCache = new Map();

    const renderFrame = function (frameIndex) {
      const boundedFrame = clamp(Math.floor(Number(frameIndex) || 0), 0, frameCount - 1);
      if (frameCache.has(boundedFrame)) {
        return frameCache.get(boundedFrame);
      }

      const frameBaseOffset = boundedFrame * singleFrameBytes;
      const values = new Float32Array(pixelCount);
      let minValue = Number.POSITIVE_INFINITY;
      let maxValue = Number.NEGATIVE_INFINITY;

      for (let index = 0; index < pixelCount; index += 1) {
        const pixelOffset = frameBaseOffset + (bitsAllocated === 16 ? index * 2 : index);
        const rawPixel = readPixelValue(dataView, pixelOffset, bitsAllocated, pixelRepresentation, littleEndian);
        const transformed = rawPixel * slope + intercept;

        values[index] = transformed;
        if (transformed < minValue) {
          minValue = transformed;
        }
        if (transformed > maxValue) {
          maxValue = transformed;
        }
      }

      let windowMin = hasWindow ? center - width / 2 : minValue;
      let windowMax = hasWindow ? center + width / 2 : maxValue;

      if (!(windowMax > windowMin)) {
        windowMin = minValue;
        windowMax = maxValue > minValue ? maxValue : minValue + 1;
      }

      const denominator = windowMax - windowMin;
      const rgba = new Uint8ClampedArray(pixelCount * 4);

      for (let index = 0; index < pixelCount; index += 1) {
        const normalized = Math.min(
          255,
          Math.max(0, Math.round(((values[index] - windowMin) / denominator) * 255))
        );
        const grayscale = photometric === "MONOCHROME1" ? 255 - normalized : normalized;
        const offset = index * 4;

        rgba[offset] = grayscale;
        rgba[offset + 1] = grayscale;
        rgba[offset + 2] = grayscale;
        rgba[offset + 3] = 255;
      }

      const renderedFrame = {
        rgba: rgba,
      };

      frameCache.set(boundedFrame, renderedFrame);
      if (frameCache.size > 8) {
        const oldestKey = frameCache.keys().next().value;
        frameCache.delete(oldestKey);
      }

      return renderedFrame;
    };

    return {
      width: columns,
      height: rows,
      frameCount: frameCount,
      renderFrame: renderFrame,
    };
  };

  const getViewerElements = function (root) {
    return {
      root: root,
      fileChip: root.querySelector(".std-medical-viewer-file-chip"),
      canvasWrap: root.querySelector("#std-medical-viewer-canvas-wrap"),
      canvas: root.querySelector("#std-medical-viewer-canvas"),
      status: root.querySelector("#std-medical-viewer-status"),
      toolbar: root.querySelector("#std-medical-viewer-toolbar"),
      zoomIn: root.querySelector("#std-medical-viewer-zoom-in"),
      zoomOut: root.querySelector("#std-medical-viewer-zoom-out"),
      reset: root.querySelector("#std-medical-viewer-reset"),
      zoomLabel: root.querySelector("#std-medical-viewer-zoom-label"),
      modeChip: root.querySelector("#std-medical-viewer-mode-chip"),
      frameControls: root.querySelector("#std-medical-viewer-frame-controls"),
      framePrev: root.querySelector("#std-medical-viewer-frame-prev"),
      frameNext: root.querySelector("#std-medical-viewer-frame-next"),
      frameLabel: root.querySelector("#std-medical-viewer-frame-label"),
      fallback: root.querySelector("#std-medical-viewer-fallback"),
      fallbackMessage: root.querySelector("#std-medical-viewer-fallback-message"),
    };
  };

  const showFallback = function (elements, message) {
    if (elements.canvasWrap) {
      elements.canvasWrap.classList.add("d-none");
    }

    if (elements.toolbar) {
      elements.toolbar.classList.add("d-none");
    }

    if (elements.frameControls) {
      elements.frameControls.classList.add("d-none");
    }

    if (elements.fallback) {
      elements.fallback.classList.remove("d-none");
    }

    if (elements.fallbackMessage) {
      elements.fallbackMessage.textContent = message;
    }

    if (elements.status) {
      elements.status.textContent = "Unable to render DICOM preview.";
    }
  };

  const syncCanvasViewportHeight = function (elements) {
    const wrap = elements.canvasWrap;
    if (!wrap || wrap.classList.contains("d-none")) {
      return;
    }

    if (elements.inlineMode) {
      const host = elements.root && elements.root.parentElement
        ? elements.root.parentElement
        : wrap.parentElement;
      const hostHeight = host && host.clientHeight > 0 ? host.clientHeight : Math.floor(window.innerHeight * 0.9);
      const inlineHeight = Math.max(240, Math.floor(hostHeight - 8));
      wrap.style.height = inlineHeight + "px";
      wrap.style.maxHeight = inlineHeight + "px";
      return;
    }

    const wrapRect = wrap.getBoundingClientRect();
    if (!Number.isFinite(wrapRect.top)) {
      return;
    }

    let bottomLimit = window.innerHeight - 12;
    const footer = document.querySelector("[role='contentinfo']") || document.querySelector("footer");
    if (footer) {
      const footerRect = footer.getBoundingClientRect();
      if (Number.isFinite(footerRect.top) && footerRect.top > wrapRect.top) {
        bottomLimit = Math.min(bottomLimit, footerRect.top - 12);
      }
    }

    const availableHeight = Math.floor(bottomLimit - wrapRect.top);
    const fixedHeight = Math.max(280, availableHeight);
    wrap.style.height = fixedHeight + "px";
    wrap.style.maxHeight = fixedHeight + "px";
  };

  const updateFrameControls = function (elements, frameIndex, frameCount, options) {
    if (!elements.frameLabel || !elements.frameControls) {
      return;
    }

    const settings = options || {};
    const labelPrefix = String(settings.labelPrefix || "Layer");
    const safeFrameCount = Math.max(1, Math.floor(Number(frameCount) || 1));
    const safeFrameIndex = clamp(Math.floor(Number(frameIndex) || 0), 0, safeFrameCount - 1);
    const forceVisible = settings.forceVisible === true;
    const hasPrevDisabledOverride = Object.prototype.hasOwnProperty.call(settings, "prevDisabled");
    const hasNextDisabledOverride = Object.prototype.hasOwnProperty.call(settings, "nextDisabled");

    if (safeFrameCount <= 1 && !forceVisible) {
      elements.frameControls.classList.add("d-none");
      elements.frameLabel.textContent = labelPrefix + " 1/1";
      return;
    }

    elements.frameControls.classList.remove("d-none");
    elements.frameLabel.textContent = labelPrefix + " " + (safeFrameIndex + 1) + "/" + safeFrameCount;

    if (elements.framePrev) {
      elements.framePrev.disabled = hasPrevDisabledOverride
        ? Boolean(settings.prevDisabled)
        : safeFrameIndex <= 0;
    }
    if (elements.frameNext) {
      elements.frameNext.disabled = hasNextDisabledOverride
        ? Boolean(settings.nextDisabled)
        : safeFrameIndex >= safeFrameCount - 1;
    }
  };

  const enableViewportInteractions = function (elements, imageStack, frameState, renderCurrentFrame) {
    const wrap = elements.canvasWrap;
    const canvas = elements.canvas;
    const zoomLabel = elements.zoomLabel;

    const state = {
      baseWidth: imageStack.width,
      baseHeight: imageStack.height,
      minScale: 0.25,
      maxScale: 8,
      scale: 1,
      initialScale: 1,
      dragging: false,
      startX: 0,
      startY: 0,
      startScrollLeft: 0,
      startScrollTop: 0,
    };
    let navigationInFlight = false;

    const centerViewport = function () {
      const contentWidth = state.baseWidth * state.scale;
      const contentHeight = state.baseHeight * state.scale;

      wrap.scrollLeft = Math.max(0, (contentWidth - wrap.clientWidth) / 2);
      wrap.scrollTop = Math.max(0, (contentHeight - wrap.clientHeight) / 2);
    };

    const updateZoomLabel = function () {
      if (zoomLabel) {
        zoomLabel.textContent = Math.round(state.scale * 100) + "%";
      }
    };

    const applyScale = function (targetScale, anchorClientX, anchorClientY) {
      const nextScale = clamp(targetScale, state.minScale, state.maxScale);
      if (!Number.isFinite(nextScale)) {
        return;
      }

      const prevScale = state.scale;
      const rect = wrap.getBoundingClientRect();
      const anchorX = anchorClientX === null || anchorClientX === undefined ? rect.width / 2 : anchorClientX - rect.left;
      const anchorY = anchorClientY === null || anchorClientY === undefined ? rect.height / 2 : anchorClientY - rect.top;

      const prevWidth = state.baseWidth * prevScale;
      const prevHeight = state.baseHeight * prevScale;
      const contentX = wrap.scrollLeft + anchorX;
      const contentY = wrap.scrollTop + anchorY;
      const ratioX = prevWidth > 0 ? contentX / prevWidth : 0;
      const ratioY = prevHeight > 0 ? contentY / prevHeight : 0;

      state.scale = nextScale;

      const nextWidth = Math.max(1, Math.round(state.baseWidth * state.scale));
      const nextHeight = Math.max(1, Math.round(state.baseHeight * state.scale));
      canvas.style.width = nextWidth + "px";
      canvas.style.height = nextHeight + "px";

      if (prevWidth > 0 && prevHeight > 0) {
        wrap.scrollLeft = Math.max(0, ratioX * nextWidth - anchorX);
        wrap.scrollTop = Math.max(0, ratioY * nextHeight - anchorY);
      }

      updateZoomLabel();
    };

    const setBaseDimensions = function (nextWidth, nextHeight, recenterViewport) {
      const normalizedWidth = Math.max(1, Math.floor(Number(nextWidth) || state.baseWidth));
      const normalizedHeight = Math.max(1, Math.floor(Number(nextHeight) || state.baseHeight));

      state.baseWidth = normalizedWidth;
      state.baseHeight = normalizedHeight;

      const scaledWidth = Math.max(1, Math.round(state.baseWidth * state.scale));
      const scaledHeight = Math.max(1, Math.round(state.baseHeight * state.scale));
      canvas.style.width = scaledWidth + "px";
      canvas.style.height = scaledHeight + "px";

      if (recenterViewport) {
        centerViewport();
      }
    };

    const canNavigateFrames = function () {
      if (frameState && typeof frameState.canNavigate === "function") {
        return Boolean(frameState.canNavigate());
      }

      return Number(frameState && frameState.total) > 1;
    };

    const navigateTo = function (targetIndex) {
      if (!frameState) {
        return Promise.resolve();
      }

      if (typeof frameState.navigateTo === "function") {
        return Promise.resolve(frameState.navigateTo(targetIndex));
      }

      const total = Math.max(1, Math.floor(Number(frameState.total) || 1));
      if (total <= 1) {
        return Promise.resolve();
      }

      const nextIndex = clamp(Math.floor(Number(targetIndex) || 0), 0, total - 1);
      if (nextIndex === frameState.index) {
        return Promise.resolve();
      }

      frameState.index = nextIndex;
      return Promise.resolve(renderCurrentFrame());
    };

    const goToFrame = function (targetIndex) {
      if (!canNavigateFrames() || navigationInFlight) {
        return;
      }

      navigationInFlight = true;
      Promise.resolve(navigateTo(targetIndex))
        .catch(function () {
          // Navigation errors are surfaced by the render pipeline.
        })
        .finally(function () {
          navigationInFlight = false;
        });
    };

    const refreshViewportLayout = function () {
      const currentScale = state.scale;
      syncCanvasViewportHeight(elements);
      applyScale(currentScale, null, null);
    };

    syncCanvasViewportHeight(elements);

    const fitScale = Math.min(
      wrap.clientWidth > 0 ? wrap.clientWidth / state.baseWidth : 1,
      wrap.clientHeight > 0 ? wrap.clientHeight / state.baseHeight : 1,
      1
    );

    state.initialScale = clamp(Number.isFinite(fitScale) ? fitScale : 1, state.minScale, state.maxScale);
    state.scale = state.initialScale;
    applyScale(state.scale, null, null);
    centerViewport();

    wrap.addEventListener(
      "wheel",
      function (event) {
        const wantsFrameNavigation = canNavigateFrames() && !event.ctrlKey && !event.metaKey;
        if (wantsFrameNavigation) {
          event.preventDefault();
          const step = event.deltaY > 0 ? 1 : -1;
          goToFrame(frameState.index + step);
          return;
        }

        event.preventDefault();
        const multiplier = event.deltaY < 0 ? 1.12 : 1 / 1.12;
        applyScale(state.scale * multiplier, event.clientX, event.clientY);
      },
      { passive: false }
    );

    wrap.addEventListener("mousedown", function (event) {
      if (event.button !== 0) {
        return;
      }

      state.dragging = true;
      state.startX = event.clientX;
      state.startY = event.clientY;
      state.startScrollLeft = wrap.scrollLeft;
      state.startScrollTop = wrap.scrollTop;
      wrap.classList.add("is-dragging");
    });

    wrap.addEventListener("mousemove", function (event) {
      if (!state.dragging) {
        return;
      }

      event.preventDefault();
      wrap.scrollLeft = state.startScrollLeft - (event.clientX - state.startX);
      wrap.scrollTop = state.startScrollTop - (event.clientY - state.startY);
    });

    const stopDragging = function () {
      state.dragging = false;
      wrap.classList.remove("is-dragging");
    };

    wrap.addEventListener("mouseleave", stopDragging);
    window.addEventListener("mouseup", stopDragging);

    wrap.setAttribute("tabindex", wrap.getAttribute("tabindex") || "0");
    wrap.addEventListener("keydown", function (event) {
      if (!canNavigateFrames()) {
        return;
      }

      if (event.key === "ArrowUp" || event.key === "PageUp") {
        event.preventDefault();
        goToFrame(frameState.index - 1);
      } else if (event.key === "ArrowDown" || event.key === "PageDown") {
        event.preventDefault();
        goToFrame(frameState.index + 1);
      }
    });

    if (elements.zoomIn) {
      elements.zoomIn.addEventListener("click", function () {
        applyScale(state.scale * 1.2, null, null);
      });
    }

    if (elements.zoomOut) {
      elements.zoomOut.addEventListener("click", function () {
        applyScale(state.scale / 1.2, null, null);
      });
    }

    if (elements.reset) {
      elements.reset.addEventListener("click", function () {
        applyScale(state.initialScale, null, null);
        centerViewport();
      });
    }

    if (elements.framePrev) {
      elements.framePrev.addEventListener("click", function () {
        goToFrame(frameState.index - 1);
      });
    }

    if (elements.frameNext) {
      elements.frameNext.addEventListener("click", function () {
        goToFrame(frameState.index + 1);
      });
    }

    window.addEventListener("resize", refreshViewportLayout);

    return {
      setBaseDimensions: setBaseDimensions,
      centerViewport: centerViewport,
      getScale: function () {
        return state.scale;
      },
    };
  };

  const loadImageStackFromUrl = function (fileUrl) {
    const safeFileUrl = String(fileUrl || "").trim();
    if (!safeFileUrl) {
      return Promise.reject(new Error("The original medical file URL is missing."));
    }

    if (typeof dicomParser === "undefined" || typeof dicomParser.parseDicom !== "function") {
      return Promise.reject(new Error("DICOM parser is not available in this page."));
    }

    return fetch(safeFileUrl, {
      method: "GET",
      credentials: "same-origin",
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error("Failed to fetch medical file.");
        }
        return response.arrayBuffer();
      })
      .then(function (buffer) {
        const byteArray = new Uint8Array(buffer);
        const dataSet = dicomParser.parseDicom(byteArray);
        return buildGrayscaleImageStack(dataSet);
      });
  };

  const mountViewer = function (root, viewerSettings) {
    if (!root || root.dataset.stdMedicalViewerMounted === "1") {
      return;
    }
    root.dataset.stdMedicalViewerMounted = "1";

    const settings = viewerSettings || {};

    if (document.body && root.getAttribute("data-std-viewer-page") === "1") {
      document.body.classList.add("std-medical-viewer-page");
    }

    const elements = getViewerElements(root);
    elements.inlineMode = root.getAttribute("data-std-viewer-inline") === "1" || root.closest("#modal-content") !== null;

    if (elements.inlineMode) {
      root.style.height = "100%";
      root.style.minHeight = "0";
      if (root.parentElement) {
        root.parentElement.style.overflow = "hidden";
      }
    }

    if (!elements.canvasWrap || !elements.canvas || !elements.status || !elements.fallback || !elements.fallbackMessage) {
      return;
    }

    syncCanvasViewportHeight(elements);

    const canVisualize = Object.prototype.hasOwnProperty.call(settings, "canVisualize")
      ? Boolean(settings.canVisualize)
      : true;
    const fileUrl = String(settings.fileUrl || "").trim();
    const fallbackMessage = String(settings.fallbackMessage || "If rendering fails, open the original file or download it.");
    const defaultFilename = String(settings.filename || "medical-image").trim() || "medical-image";

    if (!canVisualize) {
      showFallback(elements, String(settings.fallbackMessage || "Preview unavailable for this file type."));
      return;
    }

    if (!fileUrl) {
      showFallback(elements, "The original medical file URL is missing.");
      return;
    }

    const context = elements.canvas.getContext("2d");
    if (!context) {
      showFallback(elements, "Canvas context is unavailable.");
      return;
    }

    const updateFileChip = function (filename) {
      if (!elements.fileChip) {
        return;
      }

      const safeFilename = String(filename || defaultFilename || "medical-image").trim() || "medical-image";
      elements.fileChip.textContent = "File: " + safeFilename;
      elements.fileChip.setAttribute("title", safeFilename);
    };

    const updateModeChip = function (isSeriesMode) {
      if (!elements.modeChip) {
        return;
      }

      if (isSeriesMode) {
        elements.modeChip.textContent = "Series mode";
        elements.modeChip.classList.remove("d-none");
        return;
      }

      elements.modeChip.textContent = "";
      elements.modeChip.classList.add("d-none");
    };

    const showViewerShell = function () {
      elements.canvasWrap.classList.remove("d-none");
      if (elements.toolbar) {
        elements.toolbar.classList.remove("d-none");
      }
      elements.fallback.classList.add("d-none");
      syncCanvasViewportHeight(elements);
    };

    const drawFrame = function (imageStack, frameIndex) {
      const boundedIndex = clamp(Math.floor(Number(frameIndex) || 0), 0, Math.max(1, imageStack.frameCount) - 1);
      const rendered = imageStack.renderFrame(boundedIndex);

      elements.canvas.width = imageStack.width;
      elements.canvas.height = imageStack.height;

      const imageData = context.createImageData(imageStack.width, imageStack.height);
      imageData.data.set(rendered.rgba);
      context.putImageData(imageData, 0, 0);
    };

    const stackCache = new Map();
    const getCachedStack = function (targetUrl) {
      const cacheKey = String(targetUrl || "").trim();
      if (!cacheKey) {
        return Promise.reject(new Error("The original medical file URL is missing."));
      }

      if (stackCache.has(cacheKey)) {
        return stackCache.get(cacheKey);
      }

      const loadPromise = loadImageStackFromUrl(cacheKey)
        .catch(function (error) {
          stackCache.delete(cacheKey);
          throw error;
        });

      stackCache.set(cacheKey, loadPromise);
      return loadPromise;
    };

    let seriesFiles = Array.isArray(settings.seriesFiles)
      ? settings.seriesFiles
        .map(function (entry) {
          return {
            filename: String(entry && entry.filename ? entry.filename : "").trim(),
            fileUrl: String(entry && entry.fileUrl ? entry.fileUrl : "").trim(),
          };
        })
        .filter(function (entry) {
          return entry.fileUrl !== "";
        })
      : [];

    if (!seriesFiles.some(function (entry) {
      return entry.fileUrl === fileUrl;
    })) {
      seriesFiles.unshift({
        filename: defaultFilename,
        fileUrl: fileUrl,
      });
    }

    let initialSeriesIndex = Math.floor(Number(settings.initialSeriesIndex));
    if (!Number.isFinite(initialSeriesIndex) || initialSeriesIndex < 0 || initialSeriesIndex >= seriesFiles.length) {
      const matchedSeriesIndex = seriesFiles.findIndex(function (entry) {
        return entry.fileUrl === fileUrl;
      });
      initialSeriesIndex = matchedSeriesIndex >= 0 ? matchedSeriesIndex : 0;
    }

    const hasSeries = seriesFiles.length > 1;
    updateModeChip(hasSeries);
    let viewportApi = null;
    const frameState = {
      index: 0,
      total: 1,
      canNavigate: function () {
        return Number(this.total) > 1;
      },
      navigateTo: null,
    };

    const renderSingleStatus = function () {
      if (frameState.total > 1) {
        elements.status.textContent = "Rendered in embedded viewer. Layer "
          + (frameState.index + 1)
          + "/"
          + frameState.total
          + ". Wheel: layers. Ctrl/Cmd + wheel: zoom. Drag to pan.";
      }
      else {
        elements.status.textContent = "Rendered in embedded viewer. Use wheel to zoom and drag to pan.";
      }
    };

    const renderSeriesStatus = function () {
      elements.status.textContent = "Rendered in embedded viewer. Slice "
        + (frameState.index + 1)
        + "/"
        + frameState.total
        + ". Wheel: slices. Ctrl/Cmd + wheel: zoom. Drag to pan.";
    };

    const renderSeriesSlice = function (targetIndex) {
      const boundedIndex = clamp(Math.floor(Number(targetIndex) || 0), 0, seriesFiles.length - 1);
      const entry = seriesFiles[boundedIndex];

      elements.status.textContent = "Loading slice " + (boundedIndex + 1) + "/" + seriesFiles.length + "...";

      return getCachedStack(entry.fileUrl).then(function (imageStack) {
        frameState.index = boundedIndex;
        frameState.total = seriesFiles.length;

        drawFrame(imageStack, 0);
        if (viewportApi) {
          viewportApi.setBaseDimensions(imageStack.width, imageStack.height, false);
        }

        updateFileChip(entry.filename || defaultFilename);
        updateFrameControls(elements, frameState.index, frameState.total, {
          labelPrefix: "Slice",
          forceVisible: frameState.total > 1,
        });
        renderSeriesStatus();
      });
    };

    if (hasSeries) {
      frameState.total = seriesFiles.length;
      frameState.index = clamp(initialSeriesIndex, 0, seriesFiles.length - 1);
      frameState.navigateTo = function (targetIndex) {
        const nextIndex = clamp(Math.floor(Number(targetIndex) || 0), 0, frameState.total - 1);
        if (nextIndex === frameState.index) {
          return Promise.resolve();
        }

        return renderSeriesSlice(nextIndex);
      };

      elements.status.textContent = "Loading DICOM sequence...";

      renderSeriesSlice(frameState.index)
        .then(function () {
          return getCachedStack(seriesFiles[frameState.index].fileUrl);
        })
        .then(function (firstStack) {
          viewportApi = enableViewportInteractions(elements, firstStack, frameState, function () {
            return renderSeriesSlice(frameState.index);
          });
          showViewerShell();
        })
        .catch(function () {
          showFallback(elements, fallbackMessage);
        });

      return;
    }

    updateFileChip(defaultFilename);
    elements.status.textContent = "Loading DICOM file...";

    getCachedStack(fileUrl)
      .then(function (imageStack) {
        frameState.total = imageStack.frameCount;
        frameState.index = clamp(frameState.index, 0, frameState.total - 1);

        const renderSingleFrame = function () {
          drawFrame(imageStack, frameState.index);
          updateFrameControls(elements, frameState.index, frameState.total, {
            labelPrefix: "Layer",
          });
          renderSingleStatus();
        };

        frameState.navigateTo = function (targetIndex) {
          const nextIndex = clamp(Math.floor(Number(targetIndex) || 0), 0, frameState.total - 1);
          if (nextIndex === frameState.index) {
            return Promise.resolve();
          }

          frameState.index = nextIndex;
          renderSingleFrame();
          return Promise.resolve();
        };

        renderSingleFrame();
        viewportApi = enableViewportInteractions(elements, imageStack, frameState, renderSingleFrame);
        showViewerShell();
      })
      .catch(function () {
        showFallback(elements, fallbackMessage);
      });
  };

  if (typeof window !== "undefined") {
    window.StdMedicalViewer = window.StdMedicalViewer || {};
    window.StdMedicalViewer.mount = mountViewer;
  }

  Drupal.behaviors.stdMedicalViewer = {
    attach: function (context, behaviorSettings) {
      once("std-medical-viewer", "#std-medical-viewer", context).forEach(function (root) {
        const settings = behaviorSettings && behaviorSettings.stdMedicalViewer
          ? behaviorSettings.stdMedicalViewer
          : (drupalSettings && drupalSettings.stdMedicalViewer ? drupalSettings.stdMedicalViewer : {});

        mountViewer(root, settings);
      });
    },
  };
})(Drupal, once, typeof drupalSettings === "undefined" ? {} : drupalSettings);
