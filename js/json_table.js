(function ($, Drupal, once) {
  const STD_UNASSOCIATED_REFRESH_KEY = 'std.unassociated.refresh';

  let totals = {
    daFiles: 0,
    publications: 0,
    media: 0,
    medicalImages: 0,
  };

  const wrapTable = function (tableHtml) {
    return `<div class="std-table-wrap table-responsive">${tableHtml}</div>`;
  };

  const canDeleteFiles = function () {
    const stdSettings = (typeof drupalSettings !== "undefined" && drupalSettings.std)
      ? drupalSettings.std
      : {};

    if (Object.prototype.hasOwnProperty.call(stdSettings, "canDeleteFiles")) {
      return Boolean(stdSettings.canDeleteFiles);
    }

    const uid = Number((drupalSettings.user && drupalSettings.user.uid) || 0);
    return Number.isFinite(uid) && uid > 0;
  };

  const renderPager = function ($container, linkClass, currentPage, totalPages) {
    const safeTotalPages = Math.max(1, parseInt(totalPages, 10) || 1);
    const safeCurrentPage = Math.min(
      Math.max(1, parseInt(currentPage, 10) || 1),
      safeTotalPages
    );

    $container.empty();

    if (safeTotalPages <= 1) {
      return;
    }

    const pageItems = [];

    const pushLink = function (label, targetPage, disabled = false, active = false) {
      if (active) {
        pageItems.push(
          `<li class="page-item active" aria-current="page"><span class="page-link">${label}</span></li>`
        );
        return;
      }

      if (disabled) {
        pageItems.push(`<li class="page-item disabled"><span class="page-link">${label}</span></li>`);
        return;
      }

      pageItems.push(
        `<li class="page-item"><a href="#" class="page-link ${linkClass}" data-page="${targetPage}">${label}</a></li>`
      );
    };

    pushLink("First", 1, safeCurrentPage <= 1);
    pushLink("Previous", safeCurrentPage - 1, safeCurrentPage <= 1);

    const startPage = Math.max(1, safeCurrentPage - 1);
    const endPage = Math.min(safeTotalPages, safeCurrentPage + 1);
    for (let i = startPage; i <= endPage; i++) {
      pushLink(String(i), i, false, i === safeCurrentPage);
    }

    pushLink("Next", safeCurrentPage + 1, safeCurrentPage >= safeTotalPages);
    pushLink("Last", safeTotalPages, safeCurrentPage >= safeTotalPages);

    const html = `
      <nav aria-label="Pagination">
        <ul class="pagination pagination-sm justify-content-center mb-0">
          ${pageItems.join("")}
        </ul>
      </nav>
    `;

    $container.html(html);
  };

  // FunÃƒÂ§ÃƒÂ£o para recalcular e atualizar o total no DOM
  const updateTotal = function () {
    const total = totals.daFiles + totals.publications + totals.media + totals.medicalImages;
    if ($("#total_elements_count").length) {
      // $("#total_elements_count").text("Study Content (" + total + ")");
      $("#total_elements_count").text("Contents");
    } else {
      showToast("Unable to update the total content count.", "danger");
    }
  };

  const getStdStudyUri = function () {
    const stdSettings = (typeof drupalSettings !== 'undefined' && drupalSettings.std)
      ? drupalSettings.std
      : null;

    let studyUri = stdSettings ? (stdSettings.studyUri ?? stdSettings.studyuri ?? '') : '';
    if (typeof studyUri !== 'string') {
      studyUri = String(studyUri ?? '');
    }

    studyUri = studyUri.trim();
    if (!studyUri || studyUri === 'undefined' || studyUri === 'null') {
      return '';
    }

    return studyUri;
  };

  const resolveViewUrl = function (viewUrl) {
    const raw = String(viewUrl || "").trim();
    if (!raw) {
      return "";
    }

    if (/^https?:\/\//i.test(raw)) {
      return raw;
    }

    if (raw.startsWith("/")) {
      return `${window.location.origin}${raw}`;
    }

    return `${drupalSettings.path.baseUrl}std/${raw.replace(/^\/+/, "")}`;
  };

  const buildStudyUriCandidates = function (value) {
    const out = new Set();
    const raw = String(value || '').trim();
    if (!raw) {
      return out;
    }

    out.add(raw);

    try {
      const decoded = decodeURIComponent(raw).trim();
      if (decoded) {
        out.add(decoded);
      }
    } catch {
      // Ignore malformed URI sequences.
    }

    const maybeBase64 = raw.replace(/\s+/g, '');
    if (/^[A-Za-z0-9+/=]+$/.test(maybeBase64) && maybeBase64.length % 4 === 0) {
      try {
        const decoded64 = atob(maybeBase64).trim();
        if (decoded64) {
          out.add(decoded64);
          try {
            const decoded64Uri = decodeURIComponent(decoded64).trim();
            if (decoded64Uri) {
              out.add(decoded64Uri);
            }
          } catch {
            // Ignore malformed URI sequences.
          }
        }
      } catch {
        // Not a valid base64 payload.
      }
    }

    try {
      out.add(btoa(raw));
    } catch {
      // Ignore non-latin1 values.
    }

    return out;
  };

  // FunÃƒÂ§ÃƒÂ£o para carregar os dados da tabela dinamicamente
  const loadTableData = function (page) {
    if (typeof $ === "undefined") {
      showToast("jQuery is not available.", "danger");
      return;
    }

    const studyuri = getStdStudyUri();
    const elementtype = drupalSettings.std.elementtype;
    const mode = drupalSettings.std.mode;
    const pagesize = drupalSettings.std.pagesize;

    if (!studyuri || !elementtype || !mode || !pagesize) {
      console.warn('Skipping std/json-data load due to missing settings.', {
        studyuri: studyuri || '(empty)',
        elementtype: elementtype || '(empty)',
        mode: mode || '(empty)',
        pagesize: pagesize || '(empty)',
      });
      if ($("#json-table-container").length) {
        $("#json-table-container").html("<p>No data available to display.</p>");
      }
      $("#json-table-pager").empty();
      $("#json-table-stream-pager").empty();
      return;
    }

    console.log(`Loading table data: studyuri=${studyuri}, elementtype=${elementtype}, mode=${mode}, page=${page}, pagesize=${pagesize}`);
    const url =
      drupalSettings.path.baseUrl +
      `std/json-data/${encodeURIComponent(studyuri)}/${encodeURIComponent(elementtype)}/${encodeURIComponent(mode)}/${encodeURIComponent(page)}/${encodeURIComponent(pagesize)}/true`;

    $.ajax({
      url: url,
      type: "GET",
      success: function (response) {

        if (Array.isArray(response.headers) && Array.isArray(response.output) && response.output.length === 0) {
          // build an Ã¢â‚¬Å“emptyÃ¢â‚¬Â table with header + one row
          let colCount = response.headers.length;
          let table  = '<table class="table table-striped table-bordered">';
          // header
          table += '<thead><tr>';
          response.headers.forEach(h => {
            table += `<th>${h}</th>`;
          });
          table += '</tr></thead>';
          // body with one Ã¢â‚¬Å“no resultsÃ¢â‚¬Â row
          table += '<tbody>';
          table += `<tr><td colspan="${colCount}" class="text-center text-muted">No results found.</td></tr>`;
          table += '</tbody></table>';
          // inject
          $("#json-table-container").html(wrapTable(table));

          // reset count + pagination
          $("#data_files_count").text("Data Files (0)");
          totals.daFiles = 0;
          updateTotal();
          $("#json-table-pager").empty();
          $("#json-table-stream-pager").empty();
          return;
        }

        if (response.headers && response.output) {
          // Render table
          let table = '<table class="table table-striped table-bordered">';
          table += "<thead><tr>";
          response.headers.forEach(function (header) {
            table += `<th>${header}</th>`;
          });
          table += "</tr></thead><tbody>";

          response.output.forEach(function (row) {
            table += "<tr>";
            for (const key in row) {
              table += `<td>${row[key]}</td>`;
            }
            table += "</tr>";
          });

          table += "</tbody></table>";
          $("#json-table-container").html(wrapTable(table));

          // Atualiza o nÃƒÂºmero total de elementos
          if (response.pagination && response.pagination.items) {
            $("#data_files_count").text(
              "Data Files (" + response.pagination.items + ")"
            );

            totals.daFiles = parseInt(response.pagination.items, 10) || 0;
            updateTotal(); // Recalcular o total
          }

          // Reanexa os eventos aos novos elementos carregados
          attachDAEvents();

          // Renderiza a paginaÃƒÂ§ÃƒÂ£o
          if (response.pagination) {
            renderPagination(response.pagination, page);
          }
        } else {
          $("#json-table-container").html(
            "<p>No data available to display.</p>"
          );
          $("#json-table-pager").empty();
          $("#json-table-stream-pager").empty();
        }
      },
      error: function () {
        showToast("Error loading table data.", "danger");
      },
    });
  };

  // Render Pagination Function
  const renderPagination = function (pagination, currentPage) {
    const $pager = $("#json-table-pager");
    const totalPages = pagination.last_page;
    renderPager($pager, "da-page-link", currentPage, totalPages);

    $pager.off("click", ".da-page-link").on("click", ".da-page-link", function (e) {
      e.preventDefault();
      const newPage = $(this).data("page");

      // Atualizar a tabela com a nova pÃƒÂ¡gina
      loadTableData(newPage);

      // Atualizar a sessÃƒÂ£o no backend
      $.ajax({
        url: drupalSettings.path.baseUrl + "std/update-session-page",
        type: "POST", // Certifique-se de que estÃƒÂ¡ como POST
        data: {
          page: newPage,
          element_type: "da",
        },
        success: function () {},
        error: function (xhr, status, error) {
          showToast("Error updating the current page.", "danger");
        },
      });
    });
  };

  const attachDAEvents = function () {
    $(document).off("click", ".delete-button");
    $(document).off("click", ".download-button");

    $(document).on("click", ".delete-button", function (e) {
      e.preventDefault();

      const deleteUrl = $(this).data("url");

      if (confirm("Are you sure you want to delete this file?")) {
        $.ajax({
          url: deleteUrl,
          type: "POST",
          success: function (response) {
            if (response.status === "success") {
              showToast("File deleted successfully.", "success");
              const currentPage = drupalSettings.std.page || 1;
              loadTableData(currentPage);
            } else if (response.errors) {
              showToast("Unable to delete the file.", "danger");
            } else {
              showToast("An unexpected error occurred.", "danger");
              console.log(JSON.stringify(response));
            }
          },
          error: function (xhr, status, error) {
            showToast("Unable to delete the file. Please try again.", "danger");
            console.error("Error details:", error);
          },
        });
      }
    });

    $(document).on("click", ".download-media-url, .download-medical-url", function (e) {
      e.preventDefault();
      const $link = $(this);

      // Se jÃƒÂ¡ estiver baixando, ignora cliques repetidos.
      if ($link.data('downloading')) {
        return false;
      }
      $link.data('downloading', true);

      const viewUrl = $link.data("download-url");
      if (!viewUrl) {
        showToast("URL not found.", "danger");
        $link.removeData('downloading');
        return false;
      }

      fetch(viewUrl, { method: "GET" })
        .then(response => {
          if (!response.ok) {
            throw new Error(`Error downloading file: ${response.statusText}`);
          }
          const contentDisposition = response.headers.get("Content-Disposition");
          let filename = "file";
          if (contentDisposition) {
            const matches = contentDisposition.match(/filename="?(.+?)"?$/);
            if (matches && matches[1]) {
              filename = matches[1];
            }
          }
          return response.blob().then(blob => ({ blob, filename }));
        })
        .then(({ blob, filename }) => {
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement("a");
          a.style.display = "none";
          a.href = url;
          a.download = filename;
          document.body.appendChild(a);
          a.click();
          window.URL.revokeObjectURL(url);
          document.body.removeChild(a);
          showToast(`Download started: ${filename}`, "success");
        })
        .catch(error => {
          showToast(error.message || error, "danger");
        })
        .finally(() => {
          // Libera o link para futuros cliques somente apÃƒÂ³s o tÃƒÂ©rmino/falha do fetch
          $link.removeData('downloading');
        });

      return false;
    });

    $(document).on("click", ".download-publications-url", function (e) {
      e.preventDefault();
      const $link = $(this);

      // Se jÃƒÂ¡ estiver baixando, ignora cliques repetidos.
      if ($link.data('downloading')) {
        return false;
      }
      $link.data('downloading', true);

      const viewUrl = $link.data("download-url");
      if (!viewUrl) {
        showToast("URL not found.", "danger");
        $link.removeData('downloading');
        return false;
      }

      fetch(viewUrl, { method: "GET" })
        .then(response => {
          if (!response.ok) {
            throw new Error(`Error downloading file: ${response.statusText}`);
          }
          const contentDisposition = response.headers.get("Content-Disposition");
          let filename = "file";
          if (contentDisposition) {
            const matches = contentDisposition.match(/filename="?(.+?)"?$/);
            if (matches && matches[1]) {
              filename = matches[1];
            }
          }
          return response.blob().then(blob => ({ blob, filename }));
        })
        .then(({ blob, filename }) => {
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement("a");
          a.style.display = "none";
          a.href = url;
          a.download = filename;
          document.body.appendChild(a);
          a.click();
          window.URL.revokeObjectURL(url);
          document.body.removeChild(a);
          showToast(`Download started: ${filename}`, "success");
        })
        .catch(error => {
          showToast(error.message || error, "danger");
        })
        .finally(() => {
          // Libera o link para futuros cliques somente apÃƒÂ³s o tÃƒÂ©rmino/falha do fetch
          $link.removeData('downloading');
        });

      return false;
    });

    $(document).on("click", ".download-associated-url", function (e) {
      e.preventDefault();
      const $link = $(this);

      // Se jÃƒÂ¡ estiver baixando, ignora cliques repetidos.
      if ($link.data('downloading')) {
        return false;
      }
      $link.data('downloading', true);

      const viewUrl = $link.data("download-url");
      if (!viewUrl) {
        showToast("URL not found.", "danger");
        $link.removeData('downloading');
        return false;
      }

      fetch(viewUrl, { method: "GET" })
        .then(response => {
          if (!response.ok) {
            throw new Error(`Error downloading file: ${response.statusText}`);
          }
          const contentDisposition = response.headers.get("Content-Disposition");
          let filename = "file";
          if (contentDisposition) {
            const matches = contentDisposition.match(/filename="?(.+?)"?$/);
            if (matches && matches[1]) {
              filename = matches[1];
            }
          }
          return response.blob().then(blob => ({ blob, filename }));
        })
        .then(({ blob, filename }) => {
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement("a");
          a.style.display = "none";
          a.href = url;
          a.download = filename;
          document.body.appendChild(a);
          a.click();
          window.URL.revokeObjectURL(url);
          document.body.removeChild(a);
          showToast(`Download started: ${filename}`, "success");
        })
        .catch(error => {
          showToast(error.message || error, "danger");
        })
        .finally(() => {
          // Libera o link para futuros cliques somente apÃƒÂ³s o tÃƒÂ©rmino/falha do fetch
          $link.removeData('downloading');
        });

      return false;
    });

    $(document).on("click", ".download-unassociated-url", function (e) {
      e.preventDefault();
      const $link = $(this);

      // Se jÃƒÂ¡ estiver baixando, ignora cliques repetidos.
      if ($link.data('downloading')) {
        return false;
      }
      $link.data('downloading', true);

      const viewUrl = $link.data("download-url");
      if (!viewUrl) {
        showToast("URL not found.", "danger");
        $link.removeData('downloading');
        return false;
      }

      fetch(viewUrl, { method: "GET" })
        .then(response => {
          if (!response.ok) {
            throw new Error(`Error downloading file: ${response.statusText}`);
          }
          const contentDisposition = response.headers.get("Content-Disposition");
          let filename = "file";
          if (contentDisposition) {
            const matches = contentDisposition.match(/filename="?(.+?)"?$/);
            if (matches && matches[1]) {
              filename = matches[1];
            }
          }
          return response.blob().then(blob => ({ blob, filename }));
        })
        .then(({ blob, filename }) => {
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement("a");
          a.style.display = "none";
          a.href = url;
          a.download = filename;
          document.body.appendChild(a);
          a.click();
          window.URL.revokeObjectURL(url);
          document.body.removeChild(a);
          showToast(`Download started: ${filename}`, "success");
        })
        .catch(error => {
          showToast(error.message || error, "danger");
        })
        .finally(() => {
          // Libera o link para futuros cliques somente apÃƒÂ³s o tÃƒÂ©rmino/falha do fetch
          $link.removeData('downloading');
        });

      return false;
    });

  };

  // This function creates a toast notification with a message and type

  const attachDragAndDropEvents = function () {
    const dropCard = document.querySelector("#drop-card");

    if (!dropCard) {
      showToast("Upload area not found.", "danger");
      return;
    }

    const preventDefault = (e) => {
      e.preventDefault();
      e.stopPropagation();
    };

    dropCard.addEventListener("dragenter", preventDefault);
    dropCard.addEventListener("dragover", (e) => {
      preventDefault(e);
      dropCard.classList.add("drag-over");
    });
    dropCard.addEventListener("dragleave", (e) => {
      preventDefault(e);
      dropCard.classList.remove("drag-over");
    });

    const getNormalizedExtension = function (fileName) {
      const lowerName = String(fileName || "").toLowerCase();
      if (lowerName.endsWith(".nii.gz")) {
        return "nii.gz";
      }

      const lastDot = lowerName.lastIndexOf(".");
      if (lastDot === -1 || lastDot === lowerName.length - 1) {
        return "";
      }

      return lowerName.substring(lastDot + 1);
    };

    const buildUploadFileName = function (suggestedFileName, extension) {
      return extension === "nii.gz"
        ? `${suggestedFileName}.nii.gz`
        : `${suggestedFileName}.${extension}`;
    };

    const refreshStreamsTable = function (streamKey) {
      if (!streamKey) {
        return;
      }

      $.ajax({
        url: window.location.href,
        type: "GET",
        dataType: "html",
        cache: false,
        success: function (pageHtml) {
          const tmp = $("<div>").append($.parseHTML(pageHtml));

          const newTable = tmp
            .find("#dpl-streams-table")
            .filter(function () {
              return $(this).find("input[type=radio]").length > 0;
            })
            .first();

          if (!newTable.length) {
            showToast("Streams table not found during refresh.", "danger");
            return;
          }

          $("#dpl-streams-table").replaceWith(newTable);
          newTable.removeData("dpl-bound");

          Drupal.behaviors.streamSelection.attach(
            newTable.closest(".card").get(0),
            drupalSettings
          );

          const radio = $(`#dpl-streams-table input[type=radio][value="${streamKey}"]`);
          if (radio.length) {
            radio
              .closest("table")
              .find("input[type=radio]")
              .prop("checked", false)
              .data("waschecked", false)
              .closest("tr")
              .removeClass("selected");

            radio.prop("checked", true).data("waschecked", true);
            radio.trigger("click");
            radio.trigger("change");
          }
        },
        error: function () {
          showToast("Unable to refresh streams table.", "danger");
        },
      });
    };

    const uploadSingleFile = async function (file, studyuri) {
      const originalFileName = file.name;
      const extension = getNormalizedExtension(originalFileName);

      if (!extension) {
        throw new Error(`Unsupported file type: ${originalFileName}`);
      }

      if (extension === "zip") {
        throw new Error("ZIP files are not supported. Upload individual files instead.");
      }

      const checkUrl = `${drupalSettings.path.baseUrl}std/check-file-name/${encodeURIComponent(studyuri)}/${encodeURIComponent(originalFileName)}`;
      const checkResponse = await fetch(checkUrl, { method: "GET" });
      if (!checkResponse.ok) {
        throw new Error(`Could not validate file name (${checkResponse.status}).`);
      }

      const json = await checkResponse.json();
      if (!(json && json.suggestedFileName)) {
        throw new Error("Error generating file name.");
      }

      const newFileName = buildUploadFileName(json.suggestedFileName, extension);
      const renamedFile = new File([file], newFileName, { type: file.type });
      const formData = new FormData();
      formData.append("files[mt_filename]", renamedFile);

      const uploadUrl = `${drupalSettings.path.baseUrl}std/file-upload/mt_filename/${encodeURIComponent(studyuri)}`;
      const uploadResponse = await new Promise((resolve, reject) => {
        $.ajax({
          url: uploadUrl,
          type: "POST",
          data: formData,
          processData: false,
          contentType: false,
          success: function (response) {
            resolve(response);
          },
          error: function (xhr, status, error) {
            reject(new Error(error || status || "Upload failed"));
          },
        });
      });

      if (!uploadResponse.fid) {
        throw new Error("Upload failed in server response.");
      }

      return uploadResponse;
    };

    dropCard.addEventListener("drop", async (e) => {
      preventDefault(e);
      dropCard.classList.remove("drag-over");

      const files = Array.from(e.dataTransfer.files || []);
      if (!files.length) {
        return;
      }

      const studyuri = getStdStudyUri();
      if (!studyuri) {
        showToast("Study URI is missing for upload.", "warning");
        return;
      }

      let uploadedCount = 0;
      let failedCount = 0;
      let latestStreamKey = "";

      for (const file of files) {
        try {
          const response = await uploadSingleFile(file, studyuri);
          uploadedCount += 1;
          if (response.streamKey) {
            latestStreamKey = response.streamKey;
          }
        } catch (err) {
          failedCount += 1;
          showToast(`${file.name}: ${err.message || "Error uploading file."}`, "danger");
        }
      }

      if (uploadedCount > 0) {
        showToast(`${uploadedCount} file(s) uploaded successfully.`, "success");
        loadTableData(drupalSettings.std.page || 1);
        loadPublicationFiles(drupalSettings.pub.page || 1);
        loadMediaFiles(drupalSettings.media.page || 1);
        loadMedicalImageFiles(drupalSettings.medical?.page || 1);
        refreshStreamsTable(latestStreamKey);
      }

      if (failedCount > 0 && uploadedCount === 0) {
        showToast("No files were uploaded.", "warning");
      }
    });
  };

  const showToast = function (message, type = "success") {
    const toastId = `toast-${Date.now()}`;
    const toastHtml = `
      <div id="${toastId}" class="toast align-items-center text-white bg-${type}" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="6000">
        <div class="d-flex">
          <div class="toast-body">${message}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
      </div>`;
    $("#toast-container").append(toastHtml);

    const toastElement = $(`#${toastId}`);
    const toast = new bootstrap.Toast(toastElement[0]);
    toast.show();

    // Remove the toast after it's hidden
    toastElement.on("hidden.bs.toast", function () {
      toastElement.remove();
    });
  };

  //PUBLICATIONS
  const loadPublicationFiles = function (page) {
    if (typeof $ === "undefined") {
      showToast("jQuery is not available.", "danger");
      return;
    }

    const studyuri = getStdStudyUri();
    if (!studyuri) {
      console.warn('Skipping publication load: missing study URI.');
      $("#publication-table-container").html("<p>No data available to display.</p>");
      $("#publication-table-pager").empty();
      return;
    }
    const pagesize = 5;
    const url =
      drupalSettings.path.baseUrl +
      `std/get-publication-files/${encodeURIComponent(
        studyuri
      )}/${page}/${pagesize}`;
    const loggedUser = canDeleteFiles();

    $.ajax({
      url: url,
      type: "GET",
      success: function (response) {
        // se nÃƒÂ£o houver files
        if (Array.isArray(response.files) && response.files.length === 0) {
          // define os headers fixos da tabela de publicaÃƒÂ§ÃƒÂµes
          const headers = ['Filename','Operations'];
          let table = '<table class="table table-striped table-bordered">';
          table += '<thead><tr>';
          headers.forEach(h => table += `<th>${h}</th>`);
          table += '</tr></thead>';
          table += '<tbody>';
          table += `<tr><td colspan="${headers.length}" class="text-center text-muted">No results found.</td></tr>`;
          table += '</tbody></table>';
          $("#publication-table-container").html(wrapTable(table));
          totals.publications = 0;
          updateTotal();
          // limpa paginaÃƒÂ§ÃƒÂ£o
          $("#publication-table-pager").empty();
          return;
        }

        if (response.files && response.pagination) {
          let table = '<table class="table table-striped table-bordered">';
          table +=
            '<thead><tr><th>Filename</th><th style="width: 1%; white-space: nowrap; text-align: center;">Operations</th></tr></thead><tbody>';

          response.files.forEach(function (file) {
            // Verificar se o file tem a extensÃƒÂ£o `.docx`
            const isDocx = file.filename.endsWith(".docx");

            let fView = `<a href="#"
                      class="btn btn-sm btn-secondary view-media-button ${
                        isDocx ? "disabled-link" : ""
                      }"
                      data-view-url="${file.view_url}"
                      style="margin-right:5px"
                      ${isDocx ? 'aria-disabled="true" tabindex="-1"' : ""}>
                      <i class="fa-solid fa-eye"></i>
                  </a>`;
            let fDownload = `<a href="#"
                      class="btn btn-sm btn-secondary download-publications-url"
                      data-download-url="${file.download_url}"
                      style="margin-right:5px"
                        title="Download file">
                      <i class="fa-solid fa-download"></i>
                  </a>`;
            let fDelete = `<a href="#" class="btn btn-sm btn-secondary btn-danger delete-publication-button" data-url="${
                    file.delete_url
                  }">
                    <i class="fa-solid fa-trash-can"></i>
                  </a>`;

            table += `<tr>
                <td class="text-break">${file.filename}</td>
                <td style="white-space: nowrap; text-align: center;">` +
                fView +
                fDownload +
                (loggedUser ? fDelete : "") +
                `</td>
              </tr>`;
          });

          table += "</tbody></table>";
          $("#publication-table-container").html(wrapTable(table));

          if (response.files && response.pagination) {
            if (response.pagination) {
              $("#publication_files_count").text(
                // "Publications (" + response.pagination.total_files + ")"
                "Publications"
              );

              totals.publications =
                parseInt(response.pagination.total_files, 10) || 0;
              updateTotal();
            }
            renderPublicationPagination(response.pagination);
            attachPublicationDeleteEvents();
          } else {
            showToast("Incomplete response: files or pagination are missing.", "danger");
          }
        }
      },
      error: function () {
        showToast("Error loading publication files.", "danger");
      },
    });
  };

  const attachPublicationDeleteEvents = function () {
    $(document).off("click", ".delete-publication-button");
    $(document).on("click", ".delete-publication-button", function (e) {
      e.preventDefault();

      const deleteUrl =
        drupalSettings.path.baseUrl + `std/` + $(this).data("url");

      if (confirm("Are you sure you want to delete this file?")) {
        $.ajax({
          url: deleteUrl,
          type: "POST",
          success: function (response) {
            if (response.status === "success") {
              const currentPage = drupalSettings.pub.page || 1;

              // Ajustar a pÃƒÂ¡gina atual com base na ÃƒÂºltima pÃƒÂ¡gina vÃƒÂ¡lida
              const lastPage = response.last_page || 1;
              const adjustedPage = Math.min(currentPage, lastPage);

              // Atualizar a pÃƒÂ¡gina no Drupal Settings
              drupalSettings.pub.page = adjustedPage;

              // Recarregar a tabela
              loadPublicationFiles(adjustedPage);

              showToast("File deleted successfully.", "success");
            } else {
              showToast("Unable to delete the file.", "warning");
            }
          },
          error: function () {
            showToast("Error deleting the file.", "danger");
          },
        });
      }
    });
  };

  const renderPublicationPagination = function (pagination) {
    const pub_pager = jQuery("#publication-table-pager");
    renderPager(pub_pager, "pub-page-link", pagination.current_page, pagination.total_pages);

    pub_pager.off("click", ".pub-page-link").on("click", ".pub-page-link", function (e) {
      e.preventDefault();
      const newPage = $(this).data("page");

      loadPublicationFiles(newPage);

      $.ajax({
        url: drupalSettings.path.baseUrl + `std/update-session-page`,
        type: "POST",
        data: {
          page: newPage,
          element_type: "publications",
        },
        success: function () {},
        error: function (xhr, status, error) {
          showToast("Error updating publication pagination.", "danger");
        },
      });
    });
  };

  //MEDIA
  const loadMediaFiles = function (page) {
    if (typeof $ === "undefined") {
      showToast("jQuery is not available.", "danger");
      return;
    }

    const studyuri = getStdStudyUri();
    if (!studyuri) {
      console.warn('Skipping media load: missing study URI.');
      $("#media-table-container").html("<p>No data available to display.</p>");
      $("#media-table-pager").empty();
      return;
    }
    const pagesize = 5;
    const url =
      drupalSettings.path.baseUrl +
      `std/get-media-files/${encodeURIComponent(studyuri)}/${page}/${pagesize}`;
    const loggedUser = canDeleteFiles();

    $.ajax({
      url: url,
      type: "GET",
      success: function (response) {

      // se nÃƒÂ£o houver files de mÃƒÂ­dia
      if (Array.isArray(response.files) && response.files.length === 0) {
        const headers = ['Filename','Operations'];
        let table = '<table class="table table-striped table-bordered">';
        table += '<thead><tr>';
        headers.forEach(h => table += `<th>${h}</th>`);
        table += '</tr></thead>';
        table += '<tbody>';
        table += `<tr><td colspan="${headers.length}" class="text-center text-muted">No results found.</td></tr>`;
        table += '</tbody></table>';
        $("#media-table-container").html(wrapTable(table));
        totals.media = 0;
        updateTotal();
        $("#media-table-pager").empty();
        return;
      }

        if (response.files && response.pagination) {
          let table = '<table class="table table-striped table-bordered">';
          table +=
            '<thead><tr><th>Filename</th><th style="width: 1%; white-space: nowrap; text-align: center;">Operations</th></tr></thead><tbody>';

          response.files.forEach(function (file) {

            let fView = `<a href="#"
                     class="btn btn-sm btn-secondary view-media-button"
                     data-view-url="${file.view_url}"
                     style="margin-right:5px">
                     <i class="fa-solid fa-eye"></i>
                  </a>`;
            let fDownload = `<a href="#"
                      class="btn btn-sm btn-secondary download-media-url"
                      data-download-url="${file.download_url}"
                      style="margin-right:5px"
                        title="Download file">
                      <i class="fa-solid fa-download"></i>
                  </a>`;
            let fDelete = `<a href="#"
                     class="btn btn-sm btn-danger delete-media-button"
                     data-url="${file.delete_url}">
                     <i class="fa-solid fa-trash-can"></i>
                  </a>`;

            table += `<tr>
                <td class="text-break">${file.filename}</td>
                <td style="text-align: center; white-space: nowrap;">` +
                fView +
                fDownload +
                (loggedUser ? fDelete : ``) +
                `</td>
              </tr>`;
          });

          table += "</tbody></table>";
          $("#media-table-container").html(wrapTable(table));

          if (response.files && response.pagination) {
            if (response.pagination) {
              $("#media_files_count").text(
                // "Media (" + response.pagination.total_files + ")"
                "Media"
              );

              totals.media = parseInt(response.pagination.total_files, 10) || 0;
              updateTotal();
            }
            renderMediaPagination(response.pagination);
            attachMediaEvents();
          } else {
            showToast("Incomplete response: files or pagination are missing.", "danger");
          }
        }
      },
      error: function () {
        showToast("Error loading media files.", "danger");
      },
    });
  };

  const attachMediaEvents = function () {
    $(document).off("click", ".delete-media-button");
    $(document).off("click", ".view-media-button");

    $(document).on("click", ".delete-media-button", function (e) {
      e.preventDefault();

      const deleteUrl =
        drupalSettings.path.baseUrl + `std/` + $(this).data("url");

      if (confirm("Are you sure you want to delete this file?")) {
        $.ajax({
          url: deleteUrl,
          type: "POST",
          success: function (response) {
            if (response.status === "success") {
              const currentPage = drupalSettings.media.page || 1;

              const lastPage = response.last_page || 1;
              const adjustedPage = Math.min(currentPage, lastPage);

              drupalSettings.media.page = adjustedPage;

              loadMediaFiles(adjustedPage);

              showToast("File deleted successfully.", "success");
            } else {
              showToast("Unable to delete the file.", "warning");
            }
          },
          error: function () {
            showToast("Error deleting the file.", "danger");
          },
        });
      }
    });

    $(document).on("click", ".view-media-button", function (e) {
      e.preventDefault();

      const modalUrl = resolveViewUrl($(this).data("view-url"));
      if (!modalUrl) {
        showToast("Invalid file URL.", "danger");
        return;
      }

      const modalContent = document.getElementById("modal-content");
      if (modalContent) {
        modalContent.innerHTML = "";
      }

      pdfjsLib.GlobalWorkerOptions.workerSrc =
        drupalSettings.path.baseUrl + "modules/custom/rep/js/pdf.worker.min.js";

      const renderImage = (modalUrl) => {
        const newContent = `<img src="${modalUrl}" alt="Image" style="max-width:100%; height:auto;">`;
        modalContent.innerHTML = newContent;
      };

      const renderPDF = (response) => {
        const pdfData = new Uint8Array(response);
        const loadingTask = pdfjsLib.getDocument({ data: pdfData });

        loadingTask.promise
          .then(function (pdf) {
            const container = document.createElement("div");
            container.id = "pdf-scroll-container";
            container.style.display = "flex";
            container.style.flexDirection = "column";
            container.style.gap = "20px";
            container.style.overflowY = "visible";
            container.style.maxHeight = "90vh";

            for (let i = 1; i <= pdf.numPages; i++) {
              pdf.getPage(i).then(function (page) {
                const canvas = document.createElement("canvas");
                const context = canvas.getContext("2d");
                const viewport = page.getViewport({ scale: 1.5 });

                canvas.height = viewport.height;
                canvas.width = viewport.width;
                canvas.style.margin = "0 auto";

                const renderContext = {
                  canvasContext: context,
                  viewport: viewport,
                };

                page.render(renderContext);
                container.appendChild(canvas);
              });
            }
            modalContent.appendChild(container);
          })
          .catch(function (error) {
            showToast("Error loading PDF.", "danger");
            modalContent.innerHTML = "<p>Error loading PDF.</p>";
          });
      };

      const renderWord = (modalUrl) => {
        fetch(modalUrl, { method: "GET" })
          .then((res) => {
            if (!res.ok) {
              throw new Error(`HTTP error! status: ${res.status}`);
            }
            return res.json();
          })
          .then((data) => {
            if (data.viewer_url) {
              const viewerUrl = `https://view.officeapps.live.com/op/embed.aspx?src=${encodeURIComponent(
                data.viewer_url
              )}`;
              const newContent = `<iframe src="${viewerUrl}" style="width:100%; height:90vh; border:none;"></iframe>`;
              modalContent.innerHTML = newContent;
            } else {
              modalContent.innerHTML =
                "<p>Error generating preview URL. The file may not be accessible.</p>";
            }
          })
          .catch((error) => {
            showToast("Error retrieving preview URL.", "danger");
            modalContent.innerHTML = `<p>Error loading file. <a href="${modalUrl}" download>Click here to download.</a>.</p>`;
          });
      };

      $.ajax({
        url: modalUrl,
        type: "GET",
        xhrFields: {
          responseType: "arraybuffer",
        },
        success: function (response, status, xhr) {
          const contentType = xhr.getResponseHeader("Content-Type");

          if (contentType.includes("image")) {
            renderImage(modalUrl);
          } else if (contentType.includes("pdf")) {
            renderPDF(response);
          } else if (
            contentType.includes("msword") ||
            contentType.includes(
              "vnd.openxmlformats-officedocument.wordprocessingml.document"
            )
          ) {
            renderWord(modalUrl);
          } else {
            modalContent.innerHTML = `<p>Unsupported file type: ${contentType}</p>`;
          }

          $("#modal-container").removeClass("hidden");
          $(".modal-backdrop").removeClass("hidden");
        },
        error: function (xhr, status, error) {
          showToast(error, "danger");
          modalContent.innerHTML = `<p>Error loading file. <a href="${modalUrl}" download>Click here to download.</a>.</p>`;
        },
      });
    });

    $(document).on("click", ".close-btn", function () {
      const modalContainer = document.getElementById("modal-container");
      if (modalContainer) {
        modalContainer.classList.add("hidden");
        const modalPdfContent = document.getElementById("pdf-scroll-container");
        const modalContent = document.getElementById("modal-content");
        if (modalPdfContent || modalContent) {
          modalPdfContent.innerHTML = "";
          modalContent.innerHTML = "";
        }
      }
    });
  };

  // Render Media Pagination
  const renderMediaPagination = function (pagination) {
    const media_pager = jQuery("#media-table-pager");
    renderPager(media_pager, "media-page-link", pagination.current_page, pagination.total_pages);

    media_pager.off("click", ".media-page-link").on("click", ".media-page-link", function (e) {
      e.preventDefault();
      const newPage = $(this).data("page");

      loadMediaFiles(newPage);

      $.ajax({
        url: drupalSettings.path.baseUrl + `std/update-session-page`,
        type: "POST",
        data: {
          page: newPage,
          element_type: "media",
        },
        success: function () {},
        error: function (xhr, status, error) {
          showToast("Error updating media pagination.", "danger");
        },
      });
    });
  };

  // MEDICAL IMAGES
  const loadMedicalImageFiles = function (page) {
    if (typeof $ === "undefined") {
      showToast("jQuery is not available.", "danger");
      return;
    }

    const studyuri = getStdStudyUri();
    if (!studyuri) {
      console.warn("Skipping medical images load: missing study URI.");
      $("#medical-images-table-container").html("<p>No data available to display.</p>");
      $("#medical-images-table-pager").empty();
      totals.medicalImages = 0;
      updateTotal();
      return;
    }

    const pagesize = 5;
    const url =
      drupalSettings.path.baseUrl +
      `std/get-medical-image-files/${encodeURIComponent(studyuri)}/${page}/${pagesize}`;
    const loggedUser = canDeleteFiles();

    $.ajax({
      url: url,
      type: "GET",
      success: function (response) {
        if (Array.isArray(response.files) && response.files.length === 0) {
          const headers = ["Filename", "Operations"];
          let table = '<table class="table table-striped table-bordered">';
          table += "<thead><tr>";
          headers.forEach((h) => (table += `<th>${h}</th>`));
          table += "</tr></thead>";
          table += "<tbody>";
          table += `<tr><td colspan="${headers.length}" class="text-center text-muted">No results found.</td></tr>`;
          table += "</tbody></table>";
          $("#medical-images-table-container").html(wrapTable(table));
          totals.medicalImages = 0;
          updateTotal();
          $("#medical-images-table-pager").empty();
          return;
        }

        if (response.files && response.pagination) {
          let table = '<table class="table table-striped table-bordered">';
          table +=
            '<thead><tr><th>Filename</th><th style="width: 1%; white-space: nowrap; text-align: center;">Operations</th></tr></thead><tbody>';

          response.files.forEach(function (file) {
            const fView = `<a href="#"
                     class="btn btn-sm btn-secondary view-medical-image-button"
                     data-view-url="${file.view_url}"
                     style="margin-right:5px"
                       title="View medical image">
                     <i class="fa-solid fa-eye"></i>
                  </a>`;
            const fDownload = `<a href="#"
                      class="btn btn-sm btn-secondary download-medical-url"
                      data-download-url="${file.download_url}"
                      style="margin-right:5px"
                        title="Download file">
                      <i class="fa-solid fa-download"></i>
                  </a>`;
            const fDelete = `<a href="#"
                     class="btn btn-sm btn-danger delete-medical-image-button"
                     data-url="${file.delete_url}"
                       title="Delete file">
                     <i class="fa-solid fa-trash-can"></i>
                  </a>`;

            table += `<tr>
                <td class="text-break">${file.filename}</td>
                <td style="text-align: center; white-space: nowrap;">` +
              fView +
              fDownload +
              (loggedUser ? fDelete : "") +
              `</td>
              </tr>`;
          });

          table += "</tbody></table>";
          $("#medical-images-table-container").html(wrapTable(table));

          totals.medicalImages = parseInt(response.pagination.total_files, 10) || 0;
          updateTotal();
          renderMedicalImagePagination(response.pagination);
          attachMedicalImageEvents();
        } else {
          showToast("Incomplete response: files or pagination are missing.", "danger");
        }
      },
      error: function () {
        showToast("Error loading medical image files.", "danger");
      },
    });
  };

  const attachMedicalImageEvents = function () {
    $(document).off("click", ".delete-medical-image-button");
    $(document).off("click", ".view-medical-image-button");

    $(document).on("click", ".delete-medical-image-button", function (e) {
      e.preventDefault();

      const deleteUrl = drupalSettings.path.baseUrl + `std/` + $(this).data("url");

      if (confirm("Are you sure you want to delete this file?")) {
        $.ajax({
          url: deleteUrl,
          type: "POST",
          success: function (response) {
            if (response.status === "success") {
              const currentPage = drupalSettings.medical.page || 1;
              const lastPage = response.last_page || 1;
              const adjustedPage = Math.min(currentPage, lastPage);

              drupalSettings.medical.page = adjustedPage;
              loadMedicalImageFiles(adjustedPage);

              showToast("File deleted successfully.", "success");
            } else {
              showToast("Unable to delete the medical image file.", "warning");
            }
          },
          error: function () {
            showToast("Error deleting medical image file.", "danger");
          },
        });
      }
    });

    $(document).on("click", ".view-medical-image-button", function (e) {
      e.preventDefault();

      const modalUrl = resolveViewUrl($(this).data("view-url"));
      if (!modalUrl) {
        showToast("Invalid file URL.", "danger");
        return;
      }
      const ohifViewerUrl = `https://viewer.ohif.org/viewer?url=${encodeURIComponent(modalUrl)}`;
      const modalContent = document.getElementById("modal-content");
      if (!modalContent) {
        return;
      }

      modalContent.innerHTML = `
        <div class="alert alert-info mb-3" role="alert">
          Embedded OHIF is blocked in this browser. Use the actions below to view the medical file.
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-sm btn-primary" href="${ohifViewerUrl}" target="_blank" rel="noopener noreferrer">Open DICOM Viewer</a>
          <a class="btn btn-sm btn-secondary" href="${modalUrl}" target="_blank" rel="noopener noreferrer">Open Original File</a>
          <a class="btn btn-sm btn-outline-secondary" href="${modalUrl}" download>Download</a>
        </div>
        <p style="margin-top:12px; margin-bottom:0;">If the viewer cannot render this file, use Open Original File or Download.</p>
      `;

      $("#modal-container").removeClass("hidden");
      $(".modal-backdrop").removeClass("hidden");
    });
  };

  const renderMedicalImagePagination = function (pagination) {
    const medicalPager = jQuery("#medical-images-table-pager");
    renderPager(medicalPager, "medical-page-link", pagination.current_page, pagination.total_pages);

    medicalPager.off("click", ".medical-page-link").on("click", ".medical-page-link", function (e) {
      e.preventDefault();
      const newPage = $(this).data("page");

      loadMedicalImageFiles(newPage);

      $.ajax({
        url: drupalSettings.path.baseUrl + `std/update-session-page`,
        type: "POST",
        data: {
          page: newPage,
          element_type: "medical",
        },
        success: function () {},
        error: function (xhr, status, error) {
          showToast("Error updating medical image pagination.", "danger");
        },
      });
    });
  };

  Drupal.behaviors.jsonTableLoader = {
    attach: function (context, settings) {
      once("json-table", "#json-table-container", context).forEach(function () {
        const initialPage = drupalSettings.std.page || 1;
        loadTableData(initialPage);
      });
    },
  };

  Drupal.behaviors.publicationPagination = {
    attach: function (context, settings) {
      once(
        "publication-table",
        "#publication-table-container",
        context
      ).forEach(function () {
        const initialPubPage = drupalSettings.pub.page || 1;
        loadPublicationFiles(initialPubPage);
      });
    },
  };

  Drupal.behaviors.mediaPagination = {
    attach: function (context, settings) {
      once("media-table", "#media-table-container", context).forEach(
        function () {
          const initialMediaPage = drupalSettings.media.page || 1;
          loadMediaFiles(initialMediaPage);
        }
      );
    },
  };

  Drupal.behaviors.medicalImagesPagination = {
    attach: function (context, settings) {
      once("medical-images-table", "#medical-images-table-container", context).forEach(
        function () {
          const initialMedicalPage = drupalSettings.medical.page || 1;
          loadMedicalImageFiles(initialMedicalPage);
        }
      );
    },
  };

  Drupal.behaviors.unassociatedFilesRefreshListener = {
    attach: function (context) {
      once("unassociated-files-refresh-listener", "body", context).forEach(function () {
        let lastSignalTs = 0;

        const handleRefreshSignal = function (payloadRaw) {
          if (!payloadRaw) {
            return;
          }

          let payload;
          try {
            payload = JSON.parse(payloadRaw);
          } catch {
            return;
          }

          const signalStudyUri = String(payload?.studyUri || '').trim();
          const currentStudyUri = getStdStudyUri();
          const ts = Number(payload?.ts || 0);
          const source = String(payload?.source || '').trim();

          if (source && source !== 'ctt-execution') {
            return;
          }

          if (!Number.isFinite(ts) || ts <= 0 || ts <= lastSignalTs) {
            return;
          }

          if (!currentStudyUri) {
            return;
          }

          if (signalStudyUri) {
            const signalCandidates = buildStudyUriCandidates(signalStudyUri);
            const currentCandidates = buildStudyUriCandidates(currentStudyUri);

            let matchesStudy = false;
            signalCandidates.forEach(function (candidate) {
              if (currentCandidates.has(candidate)) {
                matchesStudy = true;
              }
            });

            if (!matchesStudy) {
              return;
            }
          }

          lastSignalTs = ts;
          const currentPage = drupalSettings.std.page || 1;
          loadTableData(currentPage);
        };

        window.addEventListener('storage', function (event) {
          if (!event || event.key !== STD_UNASSOCIATED_REFRESH_KEY || !event.newValue) {
            return;
          }
          handleRefreshSignal(event.newValue);
        });

        window.addEventListener('focus', function () {
          try {
            const payloadRaw = window.localStorage.getItem(STD_UNASSOCIATED_REFRESH_KEY);
            handleRefreshSignal(payloadRaw);
          } catch {
            // Ignore localStorage access issues.
          }
        });

        document.addEventListener('visibilitychange', function () {
          if (document.visibilityState !== 'visible') {
            return;
          }
          try {
            const payloadRaw = window.localStorage.getItem(STD_UNASSOCIATED_REFRESH_KEY);
            handleRefreshSignal(payloadRaw);
          } catch {
            // Ignore localStorage access issues.
          }
        });
      });
    },
  };

  // Drag and Drop Behaviour
  Drupal.behaviors.dragAndDropCard = {
    attach: function (context, settings) {
      once("drag-and-drop", "#drop-card", context).forEach(function () {
        attachDragAndDropEvents();
      });
    },
  };
})(jQuery, Drupal, once);
