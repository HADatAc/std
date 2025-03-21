(function ($, Drupal, once) {
  let totals = {
    daFiles: 0,
    publications: 0,
    media: 0, // Inicialmente zero, pode ser adicionado futuramente
  };

  // Função para recalcular e atualizar o total no DOM
  const updateTotal = function () {
    const total = totals.daFiles + totals.publications + totals.media;
    if ($("#total_elements_count").length) {
      $("#total_elements_count").text("Study Content (" + total + ")");
    } else {
      showToast("#total_elements_count not found on DOM.", "danger");
    }
  };

  // Função para carregar os dados da tabela dinamicamente
  const loadTableData = function (page) {
    if (typeof $ === "undefined") {
      showToast("jQuery not available", "danger");
      return;
    }

    const studyuri = drupalSettings.std.studyuri;
    const elementtype = drupalSettings.std.elementtype;
    const mode = drupalSettings.std.mode;
    const pagesize = drupalSettings.std.pagesize;
    const loggedUser = drupalSettings.user.logged;

    const url =
      drupalSettings.path.baseUrl +
      `std/json-data/${encodeURIComponent(studyuri)}/${encodeURIComponent(
        elementtype
      )}/${encodeURIComponent(mode)}/${encodeURIComponent(
        page
      )}/${encodeURIComponent(pagesize)}/true`;

    $.ajax({
      url: url,
      type: "GET",
      success: function (response) {
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
          $("#json-table-container").html(table);

          // Atualiza o número total de elementos
          if (response.pagination && response.pagination.items) {
            $("#data_files_count").text(
              "Data Files (" + response.pagination.items + ")"
            );

            totals.daFiles = parseInt(response.pagination.items, 10) || 0;
            updateTotal(); // Recalcular o total
          }

          // Reanexa os eventos aos novos elementos carregados
          attachDAEvents();

          // Renderiza a paginação
          if (response.pagination) {
            renderPagination(response.pagination, page);
          }
        } else {
          $("#json-table-container").html(
            "<p>No data available to display.</p>"
          );
          $("#json-table-pager").empty(); // Limpa a paginação se não houver dados
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
    $pager.empty(); // Limpar o pager existente

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
        $.ajax({
          url: deleteUrl,
          type: "POST",
          success: function (response) {
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
            showToast("Failed to delete the file. Please try again.", "danger");
            console.error("Error details:", error);
          },
        });
      }
    });

    $(document).on("click", ".download-url", function (e) {
      e.preventDefault();

      const viewUrl =
        drupalSettings.path.baseUrl + `std` + $(this).data("download-url");

      if (!viewUrl) {
        showToast("URL not found.", "danger");
        return;
      }

      fetch(viewUrl, { method: "GET" })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`Erro ao baixar o arquivo: ${response.statusText}`);
          }

          const contentDisposition = response.headers.get(
            "Content-Disposition"
          );
          let filename = "arquivo";

          if (contentDisposition) {
            const matches = contentDisposition.match(/filename="?(.+?)"?$/);
            if (matches && matches[1]) {
              filename = matches[1];
            }
          }

          return response.blob().then((blob) => ({ blob, filename }));
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
        .catch((error) => {
          showToast(error, "danger");
        });
    });
  };

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

    // Eventos de arrastar
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

      if (files.length > 0) {
        const file = files[0];
        const originalFileName = file.name;
        const fileExtension = originalFileName.split(".").pop().toLowerCase();

        const studyuri = drupalSettings.std.studyuri;

        try {
          const response = await fetch(
            drupalSettings.path.baseUrl +
              `std/check-file-name/${encodeURIComponent(
                studyuri
              )}/${encodeURIComponent(originalFileName)}`,
            {
              method: "GET",
            }
          );

          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }

          const json = await response.json();

          if (json && json.suggestedFileName) {
            const newFileName = `${json.suggestedFileName}.${fileExtension}`;
            const newFile = new File([file], newFileName, { type: file.type });

            const formData = new FormData();
            formData.append("files[mt_filename]", newFile);

            const uploadUrl =
              drupalSettings.path.baseUrl +
              "std/file-upload/mt_filename/" +
              studyuri;

            $.ajax({
              url: uploadUrl,
              type: "POST",
              data: formData,
              processData: false,
              contentType: false,
              success: function (response) {
                if (response.fid) {
                  const currentPage = drupalSettings.std.page || 1;
                  const currentPubPage = drupalSettings.pub.page || 1;
                  const currentMediaPage = drupalSettings.media.page || 1;

                  showToast("File uploaded successfully!", "success");
                  loadTableData(currentPage);
                  loadPublicationFiles(currentPubPage);
                  loadMediaFiles(currentMediaPage);
                } else {
                  showToast(
                    "Failed to upload file. Please try again.",
                    "danger"
                  );
                }
              },
              error: function () {
                showToast("Error uploading file.", "danger");
              },
            });
          } else {
            showToast("Error generating file name.", "danger");
          }
        } catch (error) {
          showToast("Error communicating with the server.", "danger");
        }
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
    const pagesize = 5;
    const url =
      drupalSettings.path.baseUrl +
      `std/get-publication-files/${encodeURIComponent(
        studyuri
      )}/${page}/${pagesize}`;
    const loggedUser = drupalSettings.user.logged;

    $.ajax({
      url: url,
      type: "GET",
      success: function (response) {
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
                      class="btn btn-sm btn-secondary download-url"
                      data-download-url="${file.download_url}"
                      style="margin-right:5px">
                      <i class="fa-solid fa-save"></i>
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
                "Publications (" + response.pagination.total_files + ")"
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
        } else {
          $("#publication-table-container").html("<p>No files available.</p>");
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

      if (confirm("Do you really want to delete this file?")) {
        $.ajax({
          url: deleteUrl,
          type: "POST",
          success: function (response) {
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
    const pagesize = 5;
    const url =
      drupalSettings.path.baseUrl +
      `std/get-media-files/${encodeURIComponent(studyuri)}/${page}/${pagesize}`;
    const loggedUser = drupalSettings.user.logged;

    $.ajax({
      url: url,
      type: "GET",
      success: function (response) {
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
                      class="btn btn-sm btn-secondary download-url"
                      data-download-url="${file.download_url}"
                      style="margin-right:5px">
                      <i class="fa-solid fa-save"></i>
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
                "Media (" + response.pagination.total_files + ")"
              );

              totals.media = parseInt(response.pagination.total_files, 10) || 0;
              updateTotal();
            }
            renderMediaPagination(response.pagination);
            attachMediaEvents();
          } else {
            showToast("Files or pagination missing in response.", "danger");
          }
        } else {
          $("#media-table-container").html("<p>No files available.</p>");
        }
      },
      error: function () {
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

              showToast(response.message, "success");
            } else {
              showToast(response.message, "warning");
            }
          },
          error: function () {
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
        drupalSettings.path.baseUrl + "modules/custom/std/js/pdf.worker.min.js";

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
            container.style.overflowY = "auto";
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
