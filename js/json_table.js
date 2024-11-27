(function ($, Drupal, once) {
  // Função para carregar os dados da tabela dinamicamente
  const loadTableData = function (page) {
    if (typeof $ === "undefined") {
      console.error("jQuery não está disponível");
      return;
    }

    const studyuri = drupalSettings.std.studyuri;
    const elementtype = drupalSettings.std.elementtype;
    const mode = drupalSettings.std.mode;
    const pagesize = drupalSettings.std.pagesize;

    // console.log(
    //   "Page=" +
    //     page +
    //     "\nStudyUri=" +
    //     studyuri +
    //     "\nElementType=" +
    //     elementtype +
    //     "\nMode=" +
    //     mode +
    //     "\nPageSize=" +
    //     pagesize
    // );

    const url =
      drupalSettings.path.baseUrl +
      `/std/json-data/${encodeURIComponent(studyuri)}/${encodeURIComponent(
        elementtype
      )}/${encodeURIComponent(mode)}/${encodeURIComponent(
        page
      )}/${encodeURIComponent(pagesize)}`;

    $.ajax({
      url: url,
      type: "GET",
      success: function (response) {
        if (response.headers && response.output) {
          // Renderiza a tabela
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
          }

          // Reanexa os eventos aos novos elementos carregados
          attachDeleteEvents();

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

  // Função para renderizar a paginação
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
        url: drupalSettings.path.baseUrl + `/std/update-session-page`,
        type: "POST",
        data: {
          page: newPage,
          element_type: "da",
        },
        success: function () {
          //console.log("Session page updated:", newPage);
        },
        error: function (xhr, status, error) {
          //console.error("Error updating session page:", error);
        },
      });
    });
  };

  // Função para anexar eventos de delete
  const attachDeleteEvents = function (studyuri) {
    $(document).off("click", ".delete-button"); // Remove eventos duplicados
    $(document).on("click", ".delete-button", function (e) {
      e.preventDefault();

      const deleteUrl =
        drupalSettings.path.baseUrl +
        `/std/delete-publication-file/` +
        studyuri +
        `/` +
        $(this).data("url");

      if (confirm("Really Delete?")) {
        $.ajax({
          url: deleteUrl,
          type: "POST",
          success: function () {
            const currentPage = drupalSettings.std.page || 1;
            loadTableData(currentPage); // Recarrega a tabela
          },
          error: function () {
            alert("Failed to delete the element.");
          },
        });
      }
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
              `/std/check-file-name/${encodeURIComponent(
                studyuri
              )}/${encodeURIComponent(originalFileName)}`, // Use o nome completo
            {
              method: "GET",
            }
          );

          //console.log("File being sent:", fileNameWithoutExtension);
          //console.log("Full file name:", file.name);

          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }

          const json = await response.json();
          //console.log("Server response:", json); // Log para depuração

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
                  showToast("File uploaded successfully!", "success");
                  loadTableData(currentPage); //Load DA Files
                  //loadPublicationFiles(currentPage); //Load Publications Files
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
            //console.error("Invalid JSON structure:", json);
            showToast("Error generating file name.", "danger");
          }
        } catch (error) {
          showToast("Error communicating with the server.", "danger");
          //console.error("Fetch error:", error);
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
      console.error("jQuery not available");
      return;
    }

    const studyuri = drupalSettings.std.studyuri;
    const pagesize = 5; // Number of files per page
    const url =
      drupalSettings.path.baseUrl +
      `/std/get-publication-files/${encodeURIComponent(
        studyuri
      )}/${page}/${pagesize}`;

    $.ajax({
      url: url,
      type: "GET",
      success: function (response) {
        console.log("Response received:", response);
        if (response.files && response.pagination) {
          let table = '<table class="table table-striped table-bordered">';
          table +=
            "<thead><tr><th>Filename</th><th>Operations</th></tr></thead><tbody>";

          response.files.forEach(function (file) {
            table += `<tr>
              <td>${file.filename}</td>
              <td>
                <a href="${file.view_url}" target="_blank">View</a>
                | 
                <a href="#" class="delete-publication-button" data-url="${file.delete_url}">Delete</a>
              </td>
            </tr>`;
          });

          table += "</tbody></table>";
          $("#publication-table-container").html(table);

          if (response.files && response.pagination) {
            console.log("Pagination data:", response.pagination);
            renderPublicationPagination(response.pagination);
            attachPublicationDeleteEvents();
          } else {
            console.error("Files or pagination missing in response.");
          }
        } else {
          $("#publication-table-container").html("<p>No files available.</p>");
        }
      },
      error: function () {
        console.error("Error loading publication files.");
      },
    });
  };

  const attachPublicationDeleteEvents = function () {
    $(document).off("click", ".delete-publication-button");
    $(document).on("click", ".delete-publication-button", function (e) {
      e.preventDefault();

      const deleteUrl = $(this).data("url");

      if (confirm("Do you really want to delete this file?")) {
        $.ajax({
          url: deleteUrl,
          type: "POST",
          success: function (response) {
            if (response.status === "success") {
              const currentPage = drupalSettings.publicationFiles.page || 1;
              loadPublicationFiles(currentPage);
            } else {
              alert("Error: " + response.error);
            }
          },
          error: function () {
            alert("Failed to delete the file.");
          },
        });
      }
    });
  };

  // // Função para renderizar a paginação
  const renderPublicationPagination = function (pagination) {

    console.log("Rendering pagination with data:", JSON.stringify(pagination));

    const $pager = $("#publication-table-pager");
    $pager.empty(); // Limpar o pager existente

    const totalPages = pagination.last_page; // Número total de páginas
    const startPage = Math.max(1, pagination.current_page - 1); // Página inicial
    const endPage = Math.min(totalPages, pagination.current_page + 1); // Página final
    const currentPage = pagination.current_page;

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
      loadPublicationFiles(newPage);

      // Atualizar a sessão no backend
      $.ajax({
        url: drupalSettings.path.baseUrl + `/std/update-session-page`,
        type: "POST",
        data: {
          page: newPage,
          element_type: "publications",
        },
        success: function () {
          console.log("Pub Session page updated:", newPage);
        },
        error: function (xhr, status, error) {
          console.error("Pub Error updating session page:", error);
        },
      });
    });
  };

  // Comportamento de carregamento da tabela
  Drupal.behaviors.jsonTableLoader = {
    attach: function (context, settings) {
      once("json-table", "#json-table-container", context).forEach(function () {
        const initialPage = drupalSettings.std.page || 1;
        loadTableData(initialPage);
      });
      once("json-table", "#publication-table-container", context).forEach(
        function () {
          const initialPubPage = drupalSettings.std.pub_page || 1;
          loadPublicationFiles(initialPubPage);
        }
      );
    },
  };

  // Comportamento de drag-and-drop
  Drupal.behaviors.dragAndDropCard = {
    attach: function (context, settings) {
      once("drag-and-drop", "#drop-card", context).forEach(function () {
        attachDragAndDropEvents();
      });
    },
  };

  //Publications
  // Drupal.behaviors.publicationFilesLoader = {
  //   attach: function (context, settings) {
  //     once(
  //       "publication-files",
  //       "#publication-table-container",
  //       context
  //     ).forEach(function () {
  //       loadPublicationFiles(1);
  //     });
  //   },
  // };
})(jQuery, Drupal, once);
