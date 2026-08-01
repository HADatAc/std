(function (Drupal, once, drupalSettings) {
  'use strict';

  function appendModeBadge(form) {
    var settings = drupalSettings.stdManageStudy || {};
    var modeLabel = String(settings.modeLabel || '').trim();
    if (!modeLabel) {
      return;
    }

    var pageTitle = null;
    var candidates = document.querySelectorAll('h1, h2, .page-title');
    candidates.forEach(function (node) {
      if (pageTitle) {
        return;
      }

      var text = String(node.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
      if (text.indexOf('manage study elements') !== -1) {
        pageTitle = node;
      }
    });

    if (!pageTitle) {
      pageTitle = document.querySelector('h1.page-title, .page-title, main h1');
    }

    if (!pageTitle || pageTitle.querySelector('.std-manage-mode-badge, .std-title-mode-badge')) {
      return;
    }

    var isOwner = settings.isOwner === true;
    var badge = document.createElement('span');
    badge.className = 'std-manage-mode-badge ' + (isOwner ? 'std-manage-mode-edit' : 'std-manage-mode-view');
    badge.textContent = modeLabel;
    pageTitle.appendChild(badge);
  }

  function enforceVisibleCollapse(root) {
    var selectors = ['#collapseDescription', '#collapseAreas', '#collapseDropCard', '#collapseWorkflow'];

    selectors.forEach(function (selector) {
      var panel = root.querySelector(selector);
      if (!panel) {
        return;
      }

      // Keep visibility explicit even if external bundles redefine .collapse.
      panel.style.setProperty('visibility', 'visible', 'important');

      var body = panel.querySelector('.accordion-body');
      if (body) {
        body.style.setProperty('visibility', 'visible', 'important');
      }
    });
  }

  function expandWorkflowPanelsForCapture(form) {
    var selectors = ['#collapseDescription', '#collapseAreas', '#collapseDropCard', '#collapseWorkflow'];
    selectors.forEach(function (selector) {
      var panel = form.querySelector(selector);
      if (!panel) {
        return;
      }

      panel.classList.add('show');
      panel.style.display = 'block';
      panel.style.height = 'auto';
    });

    var workflowBlock = form.querySelector('[data-workflow-preview-block]');
    if (workflowBlock) {
      workflowBlock.classList.remove('is-collapsed');
    }

    var workflowBody = form.querySelector('.workflow-canvas-body');
    if (workflowBody) {
      workflowBody.hidden = false;
      workflowBody.style.display = 'block';
      workflowBody.style.height = 'auto';
    }

    try {
      window.dispatchEvent(new Event('resize'));
    }
    catch (e) {
      // Ignore browsers without Event constructor support.
    }
  }

  function findWorkflowRoot(form) {
    return form.querySelector('#ctt-workflow-app')
      || form.querySelector('.ctt-workflow-preview-app')
      || form.querySelector('[data-workflow-preview-block] .workflow-canvas-body')
      || null;
  }

  function waitForWorkflowRender(form, timeoutMs) {
    return new Promise(function (resolve) {
      var start = Date.now();

      function hasRenderableWorkflow() {
        var app = findWorkflowRoot(form);
        if (!app) {
          return false;
        }
        var rect = app.getBoundingClientRect();
        var hasSize = Boolean(rect && rect.width > 40 && rect.height > 40);
        var hasNodes = app.querySelectorAll('.react-flow__node').length > 0;
        var hasViewport = Boolean(app.querySelector('.react-flow__viewport'));
        var hasBusyIndicator = Boolean(app.querySelector('.MuiCircularProgress-root, [role="progressbar"], .ctt-loading-indicator, .ajax-progress-throbber .throbber'));
        return Boolean(
          (app.querySelector('canvas') && hasSize)
          || (app.querySelector('svg') && hasSize)
          || (hasNodes && hasSize)
          || (hasViewport && hasSize && !hasBusyIndicator)
        );
      }

      function tick() {
        if (hasRenderableWorkflow()) {
          resolve();
          return;
        }
        if (Date.now() - start >= timeoutMs) {
          resolve();
          return;
        }
        window.setTimeout(tick, 120);
      }

      tick();
    });
  }

  function cloneNodeWithInlineStyles(node) {
    var clone = node.cloneNode(true);
    var sourceNodes = [node].concat(Array.prototype.slice.call(node.querySelectorAll('*')));
    var clonedNodes = [clone].concat(Array.prototype.slice.call(clone.querySelectorAll('*')));

    for (var i = 0; i < sourceNodes.length; i += 1) {
      var source = sourceNodes[i];
      var target = clonedNodes[i];
      if (!source || !target) {
        continue;
      }

      var computed = window.getComputedStyle(source);
      if (!computed) {
        continue;
      }

      var cssText = '';
      for (var j = 0; j < computed.length; j += 1) {
        var prop = computed[j];
        cssText += prop + ':' + computed.getPropertyValue(prop) + ';';
      }
      target.setAttribute('style', cssText);
    }

    return clone;
  }

  function captureElementToPng(element) {
    return new Promise(function (resolve) {
      try {
        var rect = element.getBoundingClientRect();
        var width = Math.max(1, Math.ceil(rect.width));
        var height = Math.max(1, Math.ceil(rect.height));
        if (width < 20 || height < 20) {
          resolve('');
          return;
        }

        var xhtml = 'http://www.w3.org/1999/xhtml';
        var svgNS = 'http://www.w3.org/2000/svg';
        var cloned = cloneNodeWithInlineStyles(element);
        cloned.setAttribute('xmlns', xhtml);

        var foreignObject = document.createElementNS(svgNS, 'foreignObject');
        foreignObject.setAttribute('x', '0');
        foreignObject.setAttribute('y', '0');
        foreignObject.setAttribute('width', String(width));
        foreignObject.setAttribute('height', String(height));
        foreignObject.appendChild(cloned);

        var svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('xmlns', svgNS);
        svg.setAttribute('width', String(width));
        svg.setAttribute('height', String(height));
        svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
        svg.appendChild(foreignObject);

        var serialized = new window.XMLSerializer().serializeToString(svg);
        var blob = new Blob([serialized], { type: 'image/svg+xml;charset=utf-8' });
        var blobUrl = window.URL.createObjectURL(blob);
        var image = new Image();

        image.onload = function () {
          try {
            var canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, width, height);
            ctx.drawImage(image, 0, 0);
            var pngData = canvas.toDataURL('image/png');
            window.URL.revokeObjectURL(blobUrl);
            resolve(pngData || '');
          }
          catch (e) {
            window.URL.revokeObjectURL(blobUrl);
            resolve('');
          }
        };

        image.onerror = function () {
          window.URL.revokeObjectURL(blobUrl);
          resolve('');
        };

        image.src = blobUrl;
      }
      catch (e) {
        resolve('');
      }
    });
  }

  function parseTranslate(transformValue) {
    var value = String(transformValue || '').trim();
    if (!value) {
      return { x: 0, y: 0 };
    }

    var matrixMatch = value.match(/matrix\(([^)]+)\)/);
    if (matrixMatch && matrixMatch[1]) {
      var parts = matrixMatch[1].split(',').map(function (part) { return parseFloat(part.trim()); });
      if (parts.length === 6) {
        return {
          x: isFinite(parts[4]) ? parts[4] : 0,
          y: isFinite(parts[5]) ? parts[5] : 0,
        };
      }
    }

    var translateMatch = value.match(/translate\(([^)]+)\)/);
    if (translateMatch && translateMatch[1]) {
      var translateParts = translateMatch[1].split(',').map(function (part) {
        return parseFloat(String(part).replace('px', '').trim());
      });
      return {
        x: isFinite(translateParts[0]) ? translateParts[0] : 0,
        y: isFinite(translateParts[1]) ? translateParts[1] : 0,
      };
    }

    return { x: 0, y: 0 };
  }

  function escapeXml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&apos;');
  }

  function wrapLine(text, maxChars, maxLines) {
    var words = String(text || '').replace(/\s+/g, ' ').trim().split(' ');
    if (!words.length) {
      return [''];
    }

    var lines = [];
    var current = '';
    words.forEach(function (word) {
      if (lines.length >= maxLines) {
        return;
      }

      var candidate = current ? (current + ' ' + word) : word;
      if (candidate.length <= maxChars) {
        current = candidate;
        return;
      }

      if (current) {
        lines.push(current);
      }
      current = word;
    });

    if (current && lines.length < maxLines) {
      lines.push(current);
    }

    if (lines.length > maxLines) {
      lines = lines.slice(0, maxLines);
    }

    if (lines.length === maxLines) {
      var last = lines[maxLines - 1];
      if (last.length > maxChars) {
        lines[maxLines - 1] = last.slice(0, Math.max(1, maxChars - 3)) + '...';
      }
    }

    return lines;
  }

  function captureReactFlowAsSvg(workflowRoot) {
    if (!workflowRoot) {
      return '';
    }

    var flowRoot = workflowRoot.querySelector('.react-flow');
    var viewport = workflowRoot.querySelector('.react-flow__viewport');
    var edgePaths = workflowRoot.querySelectorAll('.react-flow__edges path');
    var nodeEls = workflowRoot.querySelectorAll('.react-flow__node');

    if ((!flowRoot && !viewport) || !nodeEls || nodeEls.length === 0) {
      return '';
    }

    var nodes = [];
    var minX = Infinity;
    var minY = Infinity;
    var maxX = -Infinity;
    var maxY = -Infinity;

    nodeEls.forEach(function (nodeEl) {
      var tr = parseTranslate(nodeEl.style.transform || window.getComputedStyle(nodeEl).transform || '');
      var width = Math.max(120, Math.ceil(nodeEl.offsetWidth || nodeEl.getBoundingClientRect().width || 220));
      var height = Math.max(44, Math.ceil(nodeEl.offsetHeight || nodeEl.getBoundingClientRect().height || 64));
      var label = (nodeEl.innerText || nodeEl.textContent || '').replace(/\s+/g, ' ').trim();
      if (!label) {
        label = 'Task';
      }

      nodes.push({
        x: tr.x,
        y: tr.y,
        w: width,
        h: height,
        label: label,
      });

      minX = Math.min(minX, tr.x);
      minY = Math.min(minY, tr.y);
      maxX = Math.max(maxX, tr.x + width);
      maxY = Math.max(maxY, tr.y + height);
    });

    if (!isFinite(minX) || !isFinite(minY) || !isFinite(maxX) || !isFinite(maxY)) {
      return '';
    }

    var pad = 36;
    var viewX = Math.floor(minX - pad);
    var viewY = Math.floor(minY - pad);
    var viewW = Math.max(320, Math.ceil((maxX - minX) + (pad * 2)));
    var viewH = Math.max(200, Math.ceil((maxY - minY) + (pad * 2)));

    var svg = [];
    svg.push('<svg xmlns="http://www.w3.org/2000/svg" width="' + viewW + '" height="' + viewH + '" viewBox="' + viewX + ' ' + viewY + ' ' + viewW + ' ' + viewH + '">');
    svg.push('<defs>');
    svg.push('<pattern id="rf-dot" width="22" height="22" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1" fill="#d8dee7"/></pattern>');
    svg.push('<filter id="rf-shadow" x="-20%" y="-30%" width="160%" height="200%"><feDropShadow dx="0" dy="3" stdDeviation="4" flood-color="#9ca3af" flood-opacity="0.22"/></filter>');
    svg.push('</defs>');
    svg.push('<rect x="' + viewX + '" y="' + viewY + '" width="' + viewW + '" height="' + viewH + '" fill="#f8fafc"/>');
    svg.push('<rect x="' + viewX + '" y="' + viewY + '" width="' + viewW + '" height="' + viewH + '" fill="url(#rf-dot)"/>');

    if (viewport && edgePaths && edgePaths.length > 0) {
      var vpTransform = viewport.getAttribute('transform') || '';
      if (!vpTransform) {
        vpTransform = viewport.style.transform || '';
      }

      var edgeGroupOpen = vpTransform
        ? '<g transform="' + escapeXml(vpTransform.replace(/px/g, '')) + '">'
        : '<g>';
      svg.push(edgeGroupOpen);
      edgePaths.forEach(function (pathEl) {
        var d = pathEl.getAttribute('d') || '';
        if (!d) {
          return;
        }
        svg.push('<path d="' + escapeXml(d) + '" stroke="#9aa6b2" stroke-width="2" fill="none" stroke-linecap="round"/>');
      });
      svg.push('</g>');
    }

    nodes.forEach(function (node) {
      svg.push('<rect x="' + node.x + '" y="' + node.y + '" rx="12" ry="12" width="' + node.w + '" height="' + node.h + '" fill="#ffffff" stroke="#dbe4ef" stroke-width="1" filter="url(#rf-shadow)"/>');

      var lines = wrapLine(node.label, 46, 2);
      var centerX = node.x + Math.floor(node.w / 2);
      var baseY = node.y + Math.floor(node.h / 2) - (lines.length > 1 ? 8 : 0);
      svg.push('<text x="' + centerX + '" y="' + baseY + '" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="13" fill="#374151">');
      lines.forEach(function (line, idx) {
        var dy = idx === 0 ? '0' : '18';
        svg.push('<tspan x="' + centerX + '" dy="' + dy + '">' + escapeXml(line) + '</tspan>');
      });
      svg.push('</text>');
    });

    svg.push('</svg>');
    return svg.join('');
  }

  function captureWorkflowGraphic(form) {
    var hiddenSvgInput = form.querySelector('input[name="workflow_canvas_svg"]');
    var hiddenPngInput = form.querySelector('input[name="workflow_canvas_png"]');
    var hiddenSourceInput = form.querySelector('input[name="workflow_canvas_source"]');
    if (!hiddenSvgInput || !hiddenPngInput) {
      return;
    }

    hiddenSvgInput.value = '';
    hiddenPngInput.value = '';
    if (hiddenSourceInput) {
      hiddenSourceInput.value = 'none';
    }

    var captureSource = 'none';

    var workflowRoot = findWorkflowRoot(form);

    var reactFlowSvg = captureReactFlowAsSvg(workflowRoot);
    if (reactFlowSvg && reactFlowSvg.indexOf('<svg') === 0) {
      hiddenSvgInput.value = reactFlowSvg;
      hiddenPngInput.value = '';
      captureSource = 'reactflow-svg';
      if (hiddenSourceInput) {
        hiddenSourceInput.value = captureSource;
      }
      return Promise.resolve(true);
    }

    var canvasNode = workflowRoot ? workflowRoot.querySelector('canvas') : null;
    if (canvasNode && typeof canvasNode.toDataURL === 'function') {
      try {
        var pngData = canvasNode.toDataURL('image/png');
        if (pngData && pngData.indexOf('data:image/png;base64,') === 0) {
          hiddenPngInput.value = pngData;
          captureSource = 'canvas';
        }
      }
      catch (e) {
        hiddenPngInput.value = '';
      }
    }

    if (workflowRoot && typeof window.html2canvas === 'function') {
      return window.html2canvas(workflowRoot, {
        backgroundColor: '#ffffff',
        scale: 1.25,
        useCORS: true,
        logging: false,
        imageTimeout: 1500,
      }).then(function (capturedCanvas) {
        if (capturedCanvas && typeof capturedCanvas.toDataURL === 'function') {
          var fullPngData = capturedCanvas.toDataURL('image/png');
          if (fullPngData && fullPngData.indexOf('data:image/png;base64,') === 0) {
            hiddenPngInput.value = fullPngData;
            captureSource = 'html2canvas';
          }
        }
      }).catch(function () {
        return captureElementToPng(workflowRoot).then(function (fallbackPngData) {
          if (fallbackPngData && fallbackPngData.indexOf('data:image/png;base64,') === 0) {
            hiddenPngInput.value = fallbackPngData;
            captureSource = 'foreignObject';
          }
        });
      }).then(function () {
        if (hiddenSourceInput) {
          hiddenSourceInput.value = captureSource;
        }
        return true;
      });
    }

    if (workflowRoot) {
      return captureElementToPng(workflowRoot).then(function (fallbackPngData) {
        if (fallbackPngData && fallbackPngData.indexOf('data:image/png;base64,') === 0) {
          hiddenPngInput.value = fallbackPngData;
          captureSource = 'foreignObject';
        }

        var svgNode = workflowRoot.querySelector('svg');
        if (svgNode) {
          try {
            var clonedSvg = svgNode.cloneNode(true);
            if (!clonedSvg.getAttribute('xmlns')) {
              clonedSvg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
            }
            if (!clonedSvg.getAttribute('xmlns:xlink')) {
              clonedSvg.setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
            }

            var box = svgNode.getBoundingClientRect();
            if (box && box.width > 0 && box.height > 0) {
              if (!clonedSvg.getAttribute('width')) {
                clonedSvg.setAttribute('width', String(Math.ceil(box.width)));
              }
              if (!clonedSvg.getAttribute('height')) {
                clonedSvg.setAttribute('height', String(Math.ceil(box.height)));
              }
            }

            var serializer = new window.XMLSerializer();
            var serialized = serializer.serializeToString(clonedSvg);
            if (serialized && serialized.indexOf('<svg') !== -1) {
              hiddenSvgInput.value = serialized;
              if (captureSource === 'none') {
                captureSource = 'svg';
              }
            }
          }
          catch (e) {
            hiddenSvgInput.value = '';
          }
        }

        if (hiddenSourceInput) {
          hiddenSourceInput.value = captureSource;
        }

        return true;
      });
    }

    var svgNode = workflowRoot ? workflowRoot.querySelector('svg') : null;
    if (!svgNode) {
      if (hiddenSourceInput) {
        hiddenSourceInput.value = captureSource;
      }
      return;
    }

    try {
      var clonedSvg = svgNode.cloneNode(true);
      if (!clonedSvg.getAttribute('xmlns')) {
        clonedSvg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
      }
      if (!clonedSvg.getAttribute('xmlns:xlink')) {
        clonedSvg.setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
      }

      var box = svgNode.getBoundingClientRect();
      if (box && box.width > 0 && box.height > 0) {
        if (!clonedSvg.getAttribute('width')) {
          clonedSvg.setAttribute('width', String(Math.ceil(box.width)));
        }
        if (!clonedSvg.getAttribute('height')) {
          clonedSvg.setAttribute('height', String(Math.ceil(box.height)));
        }
      }

      var serializer = new window.XMLSerializer();
      var serialized = serializer.serializeToString(clonedSvg);
      if (serialized && serialized.indexOf('<svg') !== -1) {
        hiddenSvgInput.value = serialized;
        if (captureSource === 'none') {
          captureSource = 'svg';
        }
      }
    }
    catch (e) {
      hiddenSvgInput.value = '';
    }

    if (hiddenSourceInput) {
      hiddenSourceInput.value = captureSource;
    }

    return Promise.resolve(true);
  }

  Drupal.behaviors.stdManageStudyFix = {
    attach: function (context) {
      once('std-manage-study-badge', 'body', context).forEach(function () {
        appendModeBadge(document);

        var titleRetries = 0;
        var titleTimer = window.setInterval(function () {
          appendModeBadge(document);
          titleRetries += 1;
          if (titleRetries >= 20) {
            window.clearInterval(titleTimer);
          }
        }, 250);
      });

      once('std-manage-study-fix', '.manage-study-form', context).forEach(function (form) {
        enforceVisibleCollapse(form);
        appendModeBadge(form);

        once('std-generate-pdf-capture', '#std-generate-pdf-button', form).forEach(function (button) {
          button.addEventListener('click', function (event) {
            if (button.getAttribute('data-pdf-capture-done') === '1') {
              button.removeAttribute('data-pdf-capture-done');
              return;
            }

            event.preventDefault();
            button.setAttribute('disabled', 'disabled');

            expandWorkflowPanelsForCapture(form);

            waitForWorkflowRender(form, 10000)
              .then(function () {
                return captureWorkflowGraphic(form);
              })
              .finally(function () {
                button.removeAttribute('disabled');
                button.setAttribute('data-pdf-capture-done', '1');
                button.click();
              });
          });
        });

        var observer = new MutationObserver(function () {
          enforceVisibleCollapse(form);
        });

        observer.observe(form, {
          subtree: true,
          childList: true,
          attributes: true,
          attributeFilter: ['class', 'style']
        });

        // Guard the first seconds after lazy-loaded editor styles/scripts.
        var retries = 0;
        var timer = window.setInterval(function () {
          enforceVisibleCollapse(form);
          retries += 1;
          if (retries >= 20) {
            window.clearInterval(timer);
          }
        }, 250);
      });
    }
  };
})(Drupal, once, drupalSettings);
