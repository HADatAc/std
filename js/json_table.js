// DA Table on Study Elements
// (function ($, Drupal, once) {
//   Drupal.behaviors.jsonTableLoader = {
//     attach: function (context, settings) {
//       once("json-table", "#json-table-container", context).forEach(function (
//         element
//       ) {
//         //console.log("Initializing JSON table loader...");

//         // Função para carregar os dados da tabela
//         const loadTableData = function (page) {
//           // Recupera as variáveis de drupalSettings
//           const studyuri = drupalSettings.std.studyuri;
//           const elementtype = drupalSettings.std.elementtype;
//           const mode = drupalSettings.std.mode;
//           const pagesize = drupalSettings.std.pagesize;

//           // Monta a URL com os parâmetros GET
//           const url = `/drupal/web/std/json-data/${encodeURIComponent(
//             studyuri
//           )}/${encodeURIComponent(elementtype)}/${encodeURIComponent(
//             mode
//           )}/${encodeURIComponent(page)}/${encodeURIComponent(pagesize)}`;

//           //console.log("Requesting data from:", url); // Log para depuração

//           $.ajax({
//             url: url,
//             type: "GET",
//             success: function (response) {
//               //console.log("Data received:", response);

//               // Renderizar a tabela e a paginação
//               if (response.headers && response.output) {
//                 // Renderizar a tabela
//                 let table =
//                   '<table class="table table-striped table-bordered">';
//                 table += "<thead><tr>";
//                 response.headers.forEach(function (header) {
//                   table += `<th>${header}</th>`;
//                 });
//                 table += "</tr></thead><tbody>";

//                 response.output.forEach(function (row) {
//                   table += "<tr>";
//                   for (const key in row) {
//                     table += `<td>${row[key]}</td>`;
//                   }
//                   // Adicionando o botão de delete na última célula
//                   table += "</tr>";
//                 });

//                 table += "</tbody></table>";
//                 $("#json-table-container").html(table);

//                 // Renderizar a paginação
//                 renderPagination(response.pagination, page);

//                 // Adicionar eventos para os botões de delete
//                 bindDeleteButtons();
//               } else {
//                 $("#json-table-container").html(
//                   "<p>No data available to display.</p>"
//                 );
//               }
//             },
//             error: function (xhr, status, error) {
//               //console.error("Error loading data:", error);
//             },
//           });
//         };

//         // Função para adicionar eventos aos botões de delete
//         const bindDeleteButtons = function () {
//           $(".delete-button").on("click", function (e) {
//             e.preventDefault();

//             const deleteUrl = $(this).data("url"); // Obter a URL do atributo data-url

//             if (confirm("Really Delete?")) {
//               $.ajax({
//                 url: deleteUrl,
//                 type: "POST",
//                 success: function (response) {
//                   //console.log("Delete successful:", response);

//                   // Atualizar a tabela após a exclusão
//                   const currentPage = drupalSettings.std.page || 1;

//                   loadTableData(currentPage);
//                 },
//                 error: function (xhr, status, error) {
//                   //console.error("Error deleting element:", error);
//                   alert("Failed to delete the element.");
//                 },
//               });
//             }
//           });
//         };

//         // Função para renderizar a paginação
//         const renderPagination = function (pagination, currentPage) {
//           const $pager = $("#json-table-pager");
//           $pager.empty(); // Limpar o pager existente

//           const totalPages = pagination.last_page; // Número total de páginas
//           const startPage = Math.max(1, currentPage - 1); // Página inicial
//           const endPage = Math.min(totalPages, currentPage + 1); // Página final

//           // Botão 'Primeiro'
//           if (currentPage > 1) {
//             $pager.append(
//               `<a href="#" class="page-link" data-page="1">&laquo; First</a>`
//             );
//           }

//           // Botão 'Anterior'
//           if (currentPage > 1) {
//             $pager.append(
//               `<a href="#" class="page-link" data-page="${
//                 currentPage - 1
//               }">Previous</a>`
//             );
//           }

//           // Números das páginas
//           for (let i = startPage; i <= endPage; i++) {
//             if (i == currentPage) {
//               // Renderizar a página atual como um span (não clicável)
//               $pager.append(`<span class="current-page">${i}</span>`);
//             } else {
//               // Renderizar outras páginas como links clicáveis
//               $pager.append(
//                 `<a href="#" class="page-link" data-page="${i}">${i}</a>`
//               );
//             }
//           }

//           // Botão 'Próximo'
//           if (currentPage < totalPages) {
//             $pager.append(
//               `<a href="#" class="page-link" data-page="${
//                 currentPage + 1
//               }">Next</a>`
//             );
//           }

//           // Botão 'Último'
//           if (currentPage < totalPages) {
//             $pager.append(
//               `<a href="#" class="page-link" data-page="${totalPages}">Last &raquo;</a>`
//             );
//           }

//           // Adicionar eventos aos links
//           $(".page-link").on("click", function (e) {
//             e.preventDefault();
//             const newPage = $(this).data("page");

//             // Atualizar a tabela com a nova página
//             loadTableData(newPage);

//             // Atualizar a sessão no backend
//             $.ajax({
//               url: `/drupal/web/std/update-session-page`,
//               type: "POST",
//               data: { page: newPage },
//               success: function () {
//                 //console.log("Session page updated:", newPage);
//               },
//               error: function (xhr, status, error) {
//                 //console.error("Error updating session page:", error);
//               },
//             });
//           });
//         };

//         // Carregar a primeira página ao inicializar
//         const initialPage = drupalSettings.std.page || 1;
//         loadTableData(initialPage);
//       });
//     },
//   };
// })(jQuery, Drupal, once);

// (function ($, Drupal, once) {
//   Drupal.behaviors.dragAndDropCard = {
//     attach: function (context, settings) {
//       // Garante que o comportamento seja anexado apenas uma vez ao elemento
//       once("drag-and-drop", "#drop-card", context).forEach(function (dropCard) {
//         if (!dropCard) {
//           console.error("Drop area not found.");
//           return;
//         }

//         //console.log("Drop area initialized successfully:", dropCard);

//         let invalidFileAlertShown = false; // Flag para evitar mensagens duplicadas

//         const preventDefaultEvents = [
//           "dragenter",
//           "dragover",
//           "dragleave",
//           "drop",
//         ];
//         preventDefaultEvents.forEach((event) => {
//           dropCard.addEventListener(
//             event,
//             (e) => {
//               e.preventDefault();
//               e.stopPropagation();
//             },
//             { passive: false } // Garante que preventDefault seja permitido
//           );
//         });

//         dropCard.addEventListener("dragover", () => {
//           dropCard.classList.add("drag-over");
//         });

//         dropCard.addEventListener("dragleave", () => {
//           dropCard.classList.remove("drag-over");
//         });

//         dropCard.addEventListener("drop", (e) => {
//           e.preventDefault();
//           e.stopPropagation();

//           dropCard.classList.remove("drag-over");

//           const files = e.dataTransfer.files;

//           if (files.length > 0) {
//             const file = files[0];
//             const formData = new FormData();
//             formData.append("files[mt_filename]", file);

//             // Verifica o tipo de arquivo.
//             const validFileTypes = ["csv", "xlsx"];
//             const fileExtension = file.name.split(".").pop().toLowerCase();

//             if (!validFileTypes.includes(fileExtension)) {
//               alert("Invalid file type. Only CSV and XLSX files are allowed.");
//               return;
//             }

//             // Define o URL de upload.
//             const studyuri = drupalSettings.std.studyuri;
//             const uploadUrl =
//               drupalSettings.path.baseUrl +
//               "std/file-upload/mt_filename/" +
//               studyuri;

//             // Envia o arquivo para o servidor.
//             $.ajax({
//               url: uploadUrl,
//               type: "POST",
//               data: formData,
//               processData: false,
//               contentType: false,
//               success: function (response) {
//                 if (response.fid) {
//                   //console.log("File uploaded successfully:", response.uri);
//                   //alert("File uploaded to: " + response.uri);
//                   // Atualiza a tabela dinâmica após o upload bem-sucedido.
//                   const currentPage = drupalSettings.std.page || 1; // Página atual.
//                   loadTableData(currentPage);
//                 } else {
//                   alert("Failed to upload file. Please try again.");
//                 }
//               },
//               error: function () {
//                 alert("Error uploading file.");
//               },
//             });
//           }
//         });
//       });
//     },
//   };
// })(jQuery, Drupal, once);

// (function ($, Drupal, once) {
//   Drupal.behaviors.initializeAutocomplete = {
//     attach: function (context, settings) {
//       // Inicializa os campos de autocomplete.
//       $(
//         once(
//           "initialize-autocomplete",
//           "input[data-drupal-autocomplete-path]",
//           context
//         )
//       ).each(function () {
//         var $input = $(this);
//         var autocompletePath =
//           $input.attr("data-drupal-autocomplete-path") ||
//           $input.data("drupal-autocomplete-path");
//         if (autocompletePath) {
//           $input.autocomplete({
//             source: function (request, response) {
//               $.getJSON(autocompletePath, { q: request.term }, response);
//             },
//             minLength: 1,
//             select: function (event, ui) {
//               $input.val(ui.item.value);
//               $input.trigger("change").trigger("blur");
//             },
//           });
//         }
//       });
//     },
//   };
// })(jQuery, Drupal, once);

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

    const url = `/drupal/web/std/json-data/${encodeURIComponent(
      studyuri
    )}/${encodeURIComponent(elementtype)}/${encodeURIComponent(
      mode
    )}/${encodeURIComponent(page)}/${encodeURIComponent(pagesize)}`;

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
        url: `/drupal/web/std/update-session-page`,
        type: "POST",
        data: { page: newPage },
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
  const attachDeleteEvents = function () {
    $(document).off("click", ".delete-button"); // Remove eventos duplicados
    $(document).on("click", ".delete-button", function (e) {
      e.preventDefault();

      const deleteUrl = $(this).data("url");

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

  // Função para anexar eventos de drag-and-drop
  //   const attachDragAndDropEvents = function () {
  //     const dropCard = document.querySelector("#drop-card");

  //     if (!dropCard) {
  //       console.error("Drop area not found.");
  //       return;
  //     }

  //     const preventDefault = (e) => {
  //       e.preventDefault();
  //       e.stopPropagation();
  //     };

  //     // Eventos de arrastar
  //     dropCard.addEventListener("dragenter", preventDefault);
  //     dropCard.addEventListener("dragover", (e) => {
  //       preventDefault(e);
  //       dropCard.classList.add("drag-over");
  //     });
  //     dropCard.addEventListener("dragleave", (e) => {
  //       preventDefault(e);
  //       dropCard.classList.remove("drag-over");
  //     });
  //     dropCard.addEventListener("drop", (e) => {
  //       preventDefault(e);
  //       dropCard.classList.remove("drag-over");

  //       const files = e.dataTransfer.files;

  //       if (files.length > 0) {
  //         const file = files[0];
  //         const formData = new FormData();
  //         formData.append("files[mt_filename]", file);

  //         const studyuri = drupalSettings.std.studyuri;
  //         const uploadUrl =
  //           drupalSettings.path.baseUrl +
  //           "std/file-upload/mt_filename/" +
  //           studyuri;

  //         $.ajax({
  //           url: uploadUrl,
  //           type: "POST",
  //           data: formData,
  //           processData: false,
  //           contentType: false,
  //           success: function (response) {
  //             if (response.fid) {
  //               const currentPage = drupalSettings.std.page || 1;

  //               showToast("File uploaded successfully!", "success"); // Exibe toast de sucesso
  //               loadTableData(currentPage); // Atualiza a tabela
  //             } else {
  //               showToast("Failed to upload file. Please try again.", "danger");
  //             }
  //           },
  //           error: function () {
  //             showToast("Error uploading file.", "danger");
  //           },
  //         });
  //       }
  //     });
  //   };
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
        const fileNameWithoutExtension = originalFileName.substring(
          0,
          originalFileName.lastIndexOf(".")
        );

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
                  loadTableData(currentPage);
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

  // Comportamento de carregamento da tabela
  Drupal.behaviors.jsonTableLoader = {
    attach: function (context, settings) {
      once("json-table", "#json-table-container", context).forEach(function () {
        const initialPage = drupalSettings.std.page || 1;
        loadTableData(initialPage);
      });
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
})(jQuery, Drupal, once);
