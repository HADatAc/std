(function ($, Drupal, once) {
  let totals = {
    daFiles: 0,
    publications: 0,
    media: 0, // Inicialmente zero, pode ser adicionado futuramente
  };

  const setCardBusy = function ($anchor, isBusy, message = "Loading...") {
    const $card = $anchor.closest(".card");
    if (!$card.length) {
      return;
    }

    if (isBusy) {
      if (!$card.hasClass("std-busy")) {
        $card.addClass("std-busy");
      }
      if (!$card.find(".std-card-overlay").length) {
        $card.css("position", "relative");
        const overlayHtml = `
          <div class="std-card-overlay" style="
            position:absolute; inset:0; background:rgba(255,255,255,0.75);
            display:flex; align-items:center; justify-content:center; z-index:5;">
            <div style="text-align:center;">
              <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
              <div class="mt-2" data-std-overlay-message>${message}</div>
            </div>
          </div>`;
        $card.append(overlayHtml);
      } else {
        $card.find("[data-std-overlay-message]").text(message);
      }
    } else {
      $card.removeClass("std-busy");
      $card.find(".std-card-overlay").remove();
    }
  };

  // Função para recalcular e atualizar o total no DOM
  const updateTotal = function () {
    const total = totals.daFiles + totals.publications + totals.media;
    if ($("#total_elements_count").length) {
      // $("#total_elements_count").text("Study Content (" + total + ")");
      $("#total_elements_count").text("Contents");
    } else {
      showToast("#total_elements_count not found on DOM.", "danger");
    }
  };

  // Função para carregar os dados da tabela dinamicamente
  const loadTableData = function (page, onComplete) {
    if (typeof $ === "undefined") {
      showToast("jQuery not available", "danger");
      if (typeof onComplete === "function") {
        onComplete();
      }
      return;
    }

    const studyuri = drupalSettings.std.studyuri || drupalSettings.std.studyUri;
    if (!studyuri) {
      showToast("Study URI not found in settings.", "danger");
      return;
    }
    const elementtype = drupalSettings.std.elementtype;
    const mode = drupalSettings.std.mode;
    const pagesize = drupalSettings.std.pagesize;
    const loggedUser = drupalSettings.user.logged;

    console.log(`Loading table data: studyuri=${studyuri}, elementtype=${elementtype}, mode=${mode}, page=${page}, pagesize=${pagesize}`);
    const url =
      drupalSettings.path.baseUrl +
      `std/json-data/${encodeURIComponent(studyuri)}/${encodeURIComponent(elementtype)}/${encodeURIComponent(mode)}/${encodeURIComponent(page)}/${encodeURIComponent(pagesize)}/true`;

    setCardBusy($("#json-table-container"), true, "Loading files...");
    $.ajax({
      url: url,
      type: "GET",
      success: function (response) {
      setCardBusy($("#json-table-container"), false);

        if (Array.isArray(response.headers) && Array.isArray(response.output) && response.output.length === 0) {
          // build an “empty” table with header + one row
          let colCount = response.headers.length;
          let table  = '<table class="table table-striped table-bordered">';
          // header
          table += '<thead><tr>';
          table += '<th style="width:1%; white-space:nowrap;"><input type="checkbox" id="da-select-all" disabled></th>';
          response.headers.forEach(h => {
            table += `<th>${h}</th>`;
          });
          table += '</tr></thead>';
          // body with one “no results” row
          table += '<tbody>';
          table += `<tr><td colspan="${colCount + 1}" class="text-center text-muted">No results found.</td></tr>`;
          table += '</tbody></table>';
          // inject
          $("#json-table-container").html(table);

          ensureBulkDeleteButton();
          updateBulkDeleteState();

          // reset count + pagination
          $("#data_files_count").text("Study Data Files (0)");
          totals.daFiles = 0;
          updateTotal();
          $("#json-table-pager").empty();
          $("#json-table-stream-pager").empty();
          if (typeof onComplete === "function") {
            onComplete();
          }
          return;
        }

        if (response.headers && response.output) {
          // Render table
          let table = '<table class="table table-striped table-bordered">';
          table += "<thead><tr>";
          table += '<th style="width:1%; white-space:nowrap;"><input type="checkbox" id="da-select-all"></th>';
          response.headers.forEach(function (header) {
            table += `<th>${header}</th>`;
          });
          table += "</tr></thead><tbody>";

          response.output.forEach(function (row) {
            const opsHtml = row.element_operations || "";
            const decodedOps = $("<textarea/>").html(opsHtml).text();
            const htmlToScan = opsHtml.indexOf("delete-button") >= 0 ? opsHtml : decodedOps;
            const deleteUrlMatch = htmlToScan.match(/data-url=\\?"([^\"]+)\\?"/);
            const hasDelete = /delete-button/.test(htmlToScan) && deleteUrlMatch && deleteUrlMatch[1];
            const deleteUrl = hasDelete ? deleteUrlMatch[1] : "";
            table += "<tr>";
            table += hasDelete
              ? `<td><input type="checkbox" class="da-select" data-delete-url="${deleteUrl}"></td>`
              : `<td></td>`;
            for (const key in row) {
              table += `<td>${row[key]}</td>`;
            }
            table += "</tr>";
          });

          table += "</tbody></table>";
          $("#json-table-container").html(table);

          // Atualiza o número total de elementos
          if (response.pagination && response.pagination.items) {
            $("#data_files_count").text(
              "Study Data Files (" + response.pagination.items + ")"
            );

            totals.daFiles = parseInt(response.pagination.items, 10) || 0;
            updateTotal(); // Recalcular o total
          }

          // Reanexa os eventos aos novos elementos carregados
          attachDAEvents();
          attachBulkDeleteEvents();
          $("#da-select-all").prop("checked", false);

          // Renderiza a paginação
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
        if (typeof onComplete === "function") {
          onComplete();
        }
      },
      error: function () {
        setCardBusy($("#json-table-container"), false);
        showToast("Error loading table data.", "danger");
        if (typeof onComplete === "function") {
          onComplete();
        }
      },
    });
  };

  // Render Pagination Function
  const renderPagination = function (pagination, currentPage) {
    const $pager = $("#json-table-pager");
    $pager.empty(); // Limpar o pager existente

    ensureBulkDeleteButton();
    updateBulkDeleteState();

    const totalPages = pagination.last_page; // Número total de páginas
    const startPage = Math.max(1, currentPage - 1); // Página inicial
    const endPage = Math.min(totalPages, currentPage + 1); // Página final

    // Botão 'Primeiro'
    if (currentPage > 1) {
      $pager.append(
        `<a href="#" class="page-link" data-page="1">&laquo; First</a>`
      );
    }

    // Botão 'Anterior'
    if (currentPage > 1) {
      $pager.append(
        `<a href="#" class="page-link" data-page="${
          currentPage - 1
        }">Previous</a>`
      );
    }

    // Números das páginas
    for (let i = startPage; i <= endPage; i++) {
      if (i == currentPage) {
        // Renderizar a página atual como um span (não clicável)
        $pager.append(`<span class="current-page">${i}</span>`);
      } else {
        // Renderizar outras páginas como links clicáveis
        $pager.append(
          `<a href="#" class="page-link" data-page="${i}">${i}</a>`
        );
      }
    }

    // Botão 'Próximo'
    if (currentPage < totalPages) {
      $pager.append(
        `<a href="#" class="page-link" data-page="${currentPage + 1}">Next</a>`
      );
    }

    // Botão 'Último'
    if (currentPage < totalPages) {
      $pager.append(
        `<a href="#" class="page-link" data-page="${totalPages}">Last &raquo;</a>`
      );
    }

    // Adicionar eventos aos links
    $(".page-link").on("click", function (e) {
      e.preventDefault();
      const newPage = $(this).data("page");

      // Atualizar a tabela com a nova página
      loadTableData(newPage);

      // Atualizar a sessão no backend
      $.ajax({
        url: drupalSettings.path.baseUrl + "std/update-session-page",
        type: "POST", // Certifique-se de que está como POST
        data: {
          page: newPage,
          element_type: "da",
        },
        success: function () {},
        error: function (xhr, status, error) {
          showToast("Error updating session page.", "danger");
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
        setCardBusy($("#json-table-container"), true, "Deleting...");
        $.ajax({
          url: deleteUrl,
          type: "POST",
          success: function (response) {
            setCardBusy($("#json-table-container"), false);
            if (response.status === "success") {
              showToast("File deleted successfully!", "success");
              const currentPage = drupalSettings.std.page || 1;
              loadTableData(currentPage);
            } else if (response.errors) {
              showToast("Error: " + response.errors.join(", "), "dander");
            } else {
              showToast("Unknown error occurred.", "danger");
              console.log(JSON.stringify(response));
            }
          },
          error: function (xhr, status, error) {
            setCardBusy($("#json-table-container"), false);
            showToast("Failed to delete the file. Please try again.", "danger");
            console.error("Error details:", error);
          },
        });
      }
    });

    $(document).on("click", ".download-media-url", function (e) {
      e.preventDefault();
      const $link = $(this);

      // Se já estiver baixando, ignora cliques repetidos.
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
            throw new Error(`Erro ao baixar o arquivo: ${response.statusText}`);
          }
          const contentDisposition = response.headers.get("Content-Disposition");
          let filename = "arquivo";
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
          // Libera o link para futuros cliques somente após o término/falha do fetch
          $link.removeData('downloading');
        });

      return false;
    });

    $(document).on("click", ".download-publications-url", function (e) {
      e.preventDefault();
      const $link = $(this);

      // Se já estiver baixando, ignora cliques repetidos.
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
            throw new Error(`Erro ao baixar o arquivo: ${response.statusText}`);
          }
          const contentDisposition = response.headers.get("Content-Disposition");
          let filename = "arquivo";
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
          // Libera o link para futuros cliques somente após o término/falha do fetch
          $link.removeData('downloading');
        });

      return false;
    });

    $(document).on("click", ".download-associated-url", function (e) {
      e.preventDefault();
      const $link = $(this);

      // Se já estiver baixando, ignora cliques repetidos.
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
            throw new Error(`Erro ao baixar o arquivo: ${response.statusText}`);
          }
          const contentDisposition = response.headers.get("Content-Disposition");
          let filename = "arquivo";
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
          // Libera o link para futuros cliques somente após o término/falha do fetch
          $link.removeData('downloading');
        });

      return false;
    });

    $(document).on("click", ".download-unassociated-url", function (e) {
      e.preventDefault();
      const $link = $(this);

      // Se já estiver baixando, ignora cliques repetidos.
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
            throw new Error(`Erro ao baixar o arquivo: ${response.statusText}`);
          }
          const contentDisposition = response.headers.get("Content-Disposition");
          let filename = "arquivo";
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
          // Libera o link para futuros cliques somente após o término/falha do fetch
          $link.removeData('downloading');
        });

      return false;
    });

  };

  const ensureBulkDeleteButton = function () {
    if (!$("#bulk-delete-container").length) {
      const btnHtml = `
        <div id="bulk-delete-container" style="margin-top:6px;">
          <button id="bulk-delete-btn" class="btn btn-sm btn-danger" disabled>
            Delete selected
          </button>
        </div>`;
      $("#json-table-pager").before(btnHtml);
      $("#bulk-delete-container").hide();
    }
  };

  const updateBulkDeleteState = function () {
    const selectableCount = $(".da-select").length;
    const selectedCount = $(".da-select:checked").length;

    if (selectableCount === 0) {
      $("#bulk-delete-container").hide();
      $("#bulk-delete-btn").prop("disabled", true);
      $("#da-select-all").prop("checked", false).prop("disabled", true);
      return;
    }

    $("#da-select-all").prop("disabled", false);

    if (selectedCount > 1) {
      $("#bulk-delete-container").show();
      $("#bulk-delete-btn").prop("disabled", false);
    } else {
      $("#bulk-delete-container").hide();
      $("#bulk-delete-btn").prop("disabled", true);
    }
  };

  const attachBulkDeleteEvents = function () {
    ensureBulkDeleteButton();

    $(document).off("change", "#da-select-all");
    $(document).off("change", ".da-select");
    $(document).off("click", "#bulk-delete-btn");

    $(document).on("change", "#da-select-all", function () {
      const checked = $(this).is(":checked");
      $(".da-select").prop("checked", checked);
      updateBulkDeleteState();
    });

    $(document).on("change", ".da-select", function () {
      const total = $(".da-select").length;
      const checked = $(".da-select:checked").length;
      $("#da-select-all").prop("checked", total > 0 && checked === total);
      updateBulkDeleteState();
    });

    $(document).on("click", "#bulk-delete-btn", function (e) {
      e.preventDefault();

      const urls = $(".da-select:checked")
        .map(function () {
          return $(this).data("delete-url");
        })
        .get()
        .filter(Boolean);

      if (urls.length < 2) {
        return;
      }

      if (!confirm(`Delete ${urls.length} file(s)?`)) {
        return;
      }

      setCardBusy($("#json-table-container"), true, "Deleting selected...");

      const deleteSequential = (index) => {
        if (index >= urls.length) {
          const currentPage = drupalSettings.std.page || 1;
          loadTableData(currentPage);
          return;
        }

        $.ajax({
          url: urls[index],
          type: "POST",
          success: function () {
            deleteSequential(index + 1);
          },
          error: function () {
            showToast("Failed to delete one or more files.", "danger");
            deleteSequential(index + 1);
          },
        });
      };

      deleteSequential(0);
    });

    updateBulkDeleteState();
  };

  // This function creates a toast notification with a message and type

  const attachDragAndDropEvents = function () {
    const dropCard = document.querySelector("#drop-card");

    if (!dropCard) {
      showToast("Drop area not found.", "danger");
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

    dropCard.addEventListener("drop", async (e) => {
      preventDefault(e);
      dropCard.classList.remove("drag-over");

      const files = e.dataTransfer.files;
      if (!files.length) {
        return;
      }

      const studyuri = drupalSettings.std.studyuri;
      let lastStreamKey = null;

      const uploadSingleFile = async (file) => {
        const originalFileName = file.name;
        const fileExtension = originalFileName.split(".").pop().toLowerCase();

        // 1) Check filename
        const checkUrl = `${drupalSettings.path.baseUrl}std/check-file-name/${encodeURIComponent(studyuri)}/${encodeURIComponent(originalFileName)}`;
        const checkResponse = await fetch(checkUrl, { method: "GET" });
        if (!checkResponse.ok) throw new Error(`HTTP ${checkResponse.status}`);
        const json = await checkResponse.json();

        if (!(json && json.suggestedFileName)) {
          throw new Error("Error generating file name.");
        }

        // 2) Rename and prepare upload
        const newFileName = `${json.suggestedFileName}.${fileExtension}`;
        const newFile = new File([file], newFileName, { type: file.type });

        const formData = new FormData();
        formData.append("files[mt_filename]", newFile);

        // 3) Upload
        const uploadUrl = `${drupalSettings.path.baseUrl}std/file-upload/mt_filename/${studyuri}`;
        return new Promise((resolve, reject) => {
          $.ajax({
            url: uploadUrl,
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
              if (!response.fid) {
                reject(new Error("Failed to upload file."));
                return;
              }
              if (response.streamKey) {
                lastStreamKey = response.streamKey;
              }
              resolve(response);
            },
            error: function () {
              reject(new Error("Error uploading file."));
            }
          });
        });
      };

      setCardBusy($("#drop-card"), true, "Uploading...");
      try {
        let fileIndex = 0;
        for (const file of files) {
          fileIndex += 1;
          setCardBusy($("#drop-card"), true, `Uploading ${fileIndex} of ${files.length}...`);
          await uploadSingleFile(file);
          showToast(`Uploaded: ${file.name}`, "success");
        }

        // Refresh other tables once (force page 1 to show newest uploads)
        drupalSettings.std.page = 1;
        $.ajax({
          url: drupalSettings.path.baseUrl + "std/update-session-page",
          type: "POST",
          data: {
            page: 1,
            element_type: "da",
          },
        });
        loadTableData(1, function () {
          setCardBusy($("#drop-card"), false);
        });
        loadPublicationFiles(drupalSettings.pub.page || 1);
        loadMediaFiles(drupalSettings.media.page || 1);

        // If streamKey present, refresh streams table
        if (lastStreamKey) {
          $.ajax({
            url: window.location.href,
            type: "GET",
            dataType: "html",
            cache: false,
            success: function (pageHtml) {
              const tmp = $('<div>').append($.parseHTML(pageHtml));

              // Find only the real streams table (with radios)
              const newTable = tmp
                .find('#dpl-streams-table')
                .filter(function () {
                  const hasRadios = $(this).find('input[type=radio]').length > 0;
                  return hasRadios;
                })
                .first();

              if (!newTable.length) {
                showToast("Streams table not found in refresh.", "danger");
                return;
              }

              // Replace old table
              $('#dpl-streams-table').replaceWith(newTable);

              // *** Crucial: reset the binding flag so we can rebind events ***
              newTable.removeData('dpl-bound');

              // Re-attach only our streamSelection behavior
              Drupal.behaviors.streamSelection.attach(
                newTable.closest('.card').get(0),
                drupalSettings
              );

              // Select the new radio and trigger change
              const radio = $(`#dpl-streams-table input[type=radio][value="${lastStreamKey}"]`);
              if (radio.length) {
                radio
                  .closest('table').find('input[type=radio]')
                  .prop('checked', false).data('waschecked', false)
                  .closest('tr').removeClass('selected');

                // Mark checked + data
                radio.prop('checked', true).data('waschecked', true);

                // Fire both click AND change, to hit both handlers
                radio.trigger('click');
                radio.trigger('change');
              }
            },
            error: function () {
              showToast("Failed to refresh streams table.", "danger");
            }
          });
        }
      } catch (err) {
        showToast(err.message || "Error communicating with the server.", "danger");
      } finally {
        // drop-card busy state is cleared after table refresh
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
      showToast("jQuery not available", "danger");
      return;
    }

    const studyuri = drupalSettings.std.studyuri;
    const pagesize = 10;
    const url =
      drupalSettings.path.baseUrl +
      `std/get-publication-files/${encodeURIComponent(
        studyuri
      )}/${page}/${pagesize}`;
    const loggedUser = drupalSettings.user.logged;

    setCardBusy($("#publication-table-container"), true, "Loading publications...");
    $.ajax({
      url: url,
      type: "GET",
      success: function (response) {
      setCardBusy($("#publication-table-container"), false);
        // se não houver arquivos
        if (Array.isArray(response.files) && response.files.length === 0) {
          // define os headers fixos da tabela de publicações
          const headers = ['Filename','Operations'];
          let table = '<table class="table table-striped table-bordered">';
          table += '<thead><tr>';
          headers.forEach(h => table += `<th>${h}</th>`);
          table += '</tr></thead>';
          table += '<tbody>';
          table += `<tr><td colspan="${headers.length}" class="text-center text-muted">No results found.</td></tr>`;
          table += '</tbody></table>';
          $("#publication-table-container").html(table);
          totals.publications = 0;
          updateTotal();
          // limpa paginação
          $("#publication-table-pager").empty();
          return;
        }

        if (response.files && response.pagination) {
          let table = '<table class="table table-striped table-bordered">';
          table +=
            '<thead><tr><th>Filename</th><th style="width: 1%; white-space: nowrap; text-align: center;">Operations</th></tr></thead><tbody>';

          response.files.forEach(function (file) {
            // Verificar se o arquivo tem a extensão `.docx`
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
          $("#publication-table-container").html(table);

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
            showToast("Files or pagination missing in response.", "danger");
          }
        }
      },
      error: function () {
        setCardBusy($("#publication-table-container"), false);
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

      if (confirm("Do you really want to delete this file?")) {
        setCardBusy($("#publication-table-container"), true, "Deleting...");
        $.ajax({
          url: deleteUrl,
          type: "POST",
          success: function (response) {
            setCardBusy($("#publication-table-container"), false);
            if (response.status === "success") {
              const currentPage = drupalSettings.pub.page || 1;

              // Ajustar a página atual com base na última página válida
              const lastPage = response.last_page || 1;
              const adjustedPage = Math.min(currentPage, lastPage);

              // Atualizar a página no Drupal Settings
              drupalSettings.pub.page = adjustedPage;

              // Recarregar a tabela
              loadPublicationFiles(adjustedPage);

              showToast(response.message, "success");
            } else {
              showToast(response.message, "warning");
            }
          },
          error: function () {
            setCardBusy($("#publication-table-container"), false);
            showToast("Error: " + response.error, "danger");
          },
        });
      }
    });
  };

  const renderPublicationPagination = function (pagination) {
    const pub_pager = jQuery("#publication-table-pager");
    pub_pager.empty();

    const totalPages = pagination.total_pages;
    const startPage = Math.max(1, pagination.current_page - 1);
    const endPage = Math.min(totalPages, pagination.current_page + 1);
    const currentPage = pagination.current_page;

    if (currentPage > 1) {
      pub_pager.append(
        `<a href="#" class="pub-page-link" data-page="1">&laquo; First</a>`
      );
    }

    if (currentPage > 1) {
      pub_pager.append(
        `<a href="#" class="pub-page-link" data-page="${
          currentPage - 1
        }">Previous</a>`
      );
    }

    for (let i = startPage; i <= endPage; i++) {
      if (i == currentPage) {
        pub_pager.append(`<span class="current-page">${i}</span>`);
      } else {
        pub_pager.append(
          `<a href="#" class="pub-page-link" data-page="${i}">${i}</a>`
        );
      }
    }

    if (currentPage < totalPages) {
      pub_pager.append(
        `<a href="#" class="pub-page-link" data-page="${
          currentPage + 1
        }">Next</a>`
      );
    }

    if (currentPage < totalPages) {
      pub_pager.append(
        `<a href="#" class="pub-page-link" data-page="${totalPages}">Last &raquo;</a>`
      );
    }

    $(".pub-page-link").on("click", function (e) {
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
          showToast(error, "danger");
        },
      });
    });
  };

  //MEDIA
  const loadMediaFiles = function (page) {
    if (typeof $ === "undefined") {
      showToast("jQuery not available", "danger");
      return;
    }

    const studyuri = drupalSettings.std.studyuri;
    const pagesize = 10;
    const url =
      drupalSettings.path.baseUrl +
      `std/get-media-files/${encodeURIComponent(studyuri)}/${page}/${pagesize}`;
    const loggedUser = drupalSettings.user.logged;

    setCardBusy($("#media-table-container"), true, "Loading media...");
    $.ajax({
      url: url,
      type: "GET",
      success: function (response) {
      setCardBusy($("#media-table-container"), false);

      // se não houver arquivos de mídia
      if (Array.isArray(response.files) && response.files.length === 0) {
        const headers = ['Filename','Operations'];
        let table = '<table class="table table-striped table-bordered">';
        table += '<thead><tr>';
        headers.forEach(h => table += `<th>${h}</th>`);
        table += '</tr></thead>';
        table += '<tbody>';
        table += `<tr><td colspan="${headers.length}" class="text-center text-muted">No results found.</td></tr>`;
        table += '</tbody></table>';
        $("#media-table-container").html(table);
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
          $("#media-table-container").html(table);

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
            showToast("Files or pagination missing in response.", "danger");
          }
        }
      },
      error: function () {
        setCardBusy($("#media-table-container"), false);
        showToast("Error loading publication files.", "danger");
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

      if (confirm("Do you really want to delete this file?")) {
        setCardBusy($("#media-table-container"), true, "Deleting...");
        $.ajax({
          url: deleteUrl,
          type: "POST",
          success: function (response) {
            setCardBusy($("#media-table-container"), false);
            if (response.status === "success") {
              const currentPage = drupalSettings.media.page || 1;

              const lastPage = response.last_page || 1;
              const adjustedPage = Math.min(currentPage, lastPage);

              drupalSettings.media.page = adjustedPage;

              loadMediaFiles(adjustedPage);

              showToast(response.message, "success");
            } else {
              showToast(response.message, "warning");
            }
          },
          error: function () {
            setCardBusy($("#media-table-container"), false);
            showToast("Error: " + response.error, "danger");
          },
        });
      }
    });

    $(document).on("click", ".view-media-button", function (e) {
      e.preventDefault();

      const modalUrl =
        drupalSettings.path.baseUrl + `std/` + $(this).data("view-url");

      const modalContent = document.getElementById("modal-content");
      if (modalContent) {
        modalContent.innerHTML = "";
      }

      pdfjsLib.GlobalWorkerOptions.workerSrc =
        drupalSettings.path.baseUrl + "modules/custom/rep/js/pdf.worker.min.js";

      const renderImage = (modalUrl) => {
        const newContent = `<img src="${modalUrl}" alt="Imagem" style="max-width:100%; height:auto;">`;
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
            showToast("Error loading PDF", "danger");
            modalContent.innerHTML = "<p>Error Loading PDF.</p>";
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
                "<p>Erro ao gerar a URL de visualização. O arquivo pode não ser acessível.</p>";
            }
          })
          .catch((error) => {
            showToast("Erro get URL for Viewer:", "danger");
            modalContent.innerHTML = `<p>Error loading file. <a href="${modalUrl}" download>Press here to Download</a>.</p>`;
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
            modalContent.innerHTML = `<p>Tipo de arquivo não suportado: ${contentType}</p>`;
          }

          $("#modal-container").removeClass("hidden");
          $(".modal-backdrop").removeClass("hidden");
        },
        error: function (xhr, status, error) {
          showToast(error, "danger");
          modalContent.innerHTML = `<p>Erro ao carregar o arquivo. <a href="${modalUrl}" download>Clique aqui para baixá-lo</a>.</p>`;
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
    media_pager.empty();

    const totalPages = pagination.total_pages;
    const startPage = Math.max(1, pagination.current_page - 1);
    const endPage = Math.min(totalPages, pagination.current_page + 1);
    const currentPage = pagination.current_page;

    if (currentPage > 1) {
      media_pager.append(
        `<a href="#" class="media-page-link" data-page="1">&laquo; First</a>`
      );
    }

    if (currentPage > 1) {
      media_pager.append(
        `<a href="#" class="media-page-link" data-page="${
          currentPage - 1
        }">Previous</a>`
      );
    }

    for (let i = startPage; i <= endPage; i++) {
      if (i == currentPage) {
        media_pager.append(`<span class="current-page">${i}</span>`);
      } else {
        media_pager.append(
          `<a href="#" class="media-page-link" data-page="${i}">${i}</a>`
        );
      }
    }

    if (currentPage < totalPages) {
      media_pager.append(
        `<a href="#" class="media-page-link" data-page="${
          currentPage + 1
        }">Next</a>`
      );
    }

    if (currentPage < totalPages) {
      media_pager.append(
        `<a href="#" class="media-page-link" data-page="${totalPages}">Last &raquo;</a>`
      );
    }

    $(".media-page-link").on("click", function (e) {
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
          showToast(error, "danger");
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

  // Drag and Drop Behaviour
  Drupal.behaviors.dragAndDropCard = {
    attach: function (context, settings) {
      once("drag-and-drop", "#drop-card", context).forEach(function () {
        attachDragAndDropEvents();
      });
    },
  };
})(jQuery, Drupal, once);
