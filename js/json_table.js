// DA Table on Study Elements
(function ($, Drupal, once) {
  Drupal.behaviors.jsonTableLoader = {
    attach: function (context, settings) {
      once("json-table", "#json-table-container", context).forEach(function (
        element
      ) {
        //console.log("Initializing JSON table loader...");

        // Função para carregar os dados da tabela
        const loadTableData = function (page) {
          // Recupera as variáveis de drupalSettings
          const studyuri = drupalSettings.std.studyuri;
          const elementtype = drupalSettings.std.elementtype;
          const mode = drupalSettings.std.mode;
          const pagesize = drupalSettings.std.pagesize;

          // Monta a URL com os parâmetros GET
          const url = `/drupal/web/std/json-data/${encodeURIComponent(
            studyuri
          )}/${encodeURIComponent(elementtype)}/${encodeURIComponent(
            mode
          )}/${encodeURIComponent(page)}/${encodeURIComponent(pagesize)}`;

          //console.log("Requesting data from:", url); // Log para depuração

          $.ajax({
            url: url,
            type: "GET",
            success: function (response) {
              //console.log("Data received:", response);

              // Renderizar a tabela e a paginação
              if (response.headers && response.output) {
                // Renderizar a tabela
                let table =
                  '<table class="table table-striped table-bordered">';
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
                  // Adicionando o botão de delete na última célula
                  table += "</tr>";
                });

                table += "</tbody></table>";
                $("#json-table-container").html(table);

                // Renderizar a paginação
                renderPagination(response.pagination, page);

                // Adicionar eventos para os botões de delete
                bindDeleteButtons();
              } else {
                $("#json-table-container").html(
                  "<p>No data available to display.</p>"
                );
              }
            },
            error: function (xhr, status, error) {
              //console.error("Error loading data:", error);
            },
          });
        };

        // Função para adicionar eventos aos botões de delete
        const bindDeleteButtons = function () {
          $(".delete-button").on("click", function (e) {
            e.preventDefault();

            const deleteUrl = $(this).data("url"); // Obter a URL do atributo data-url

            if (confirm("Really Delete?")) {
              $.ajax({
                url: deleteUrl,
                type: "POST",
                success: function (response) {
                  //console.log("Delete successful:", response);

                  // Atualizar a tabela após a exclusão
                  const currentPage = drupalSettings.std.page || 1;

                  loadTableData(currentPage);
                },
                error: function (xhr, status, error) {
                  //console.error("Error deleting element:", error);
                  alert("Failed to delete the element.");
                },
              });
            }
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
              `<a href="#" class="page-link" data-page="${
                currentPage + 1
              }">Next</a>`
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

        // Carregar a primeira página ao inicializar
        const initialPage = drupalSettings.std.page || 1;
        loadTableData(initialPage);
      });
    },
  };
})(jQuery, Drupal, once);

//DRAG & DROP
// (function ($, Drupal) {
//   Drupal.behaviors.dragAndDropCard = {
//     attach: function (context, settings) {
//       const dropCard = document.getElementById("drop-card");

//       if (!dropCard) {
//         console.error("Drop area not found.");
//         return;
//       }

//       console.log("Drop area found:", dropCard);

//       ["dragenter", "dragover", "dragleave", "drop"].forEach((event) => {
//         dropCard.addEventListener(event, (e) => {
//           e.preventDefault();
//           e.stopPropagation();
//         });
//       });

//       dropCard.addEventListener("dragover", () => {
//         dropCard.classList.add("drag-over");
//       });

//       dropCard.addEventListener("dragleave", () => {
//         dropCard.classList.remove("drag-over");
//       });

//       dropCard.addEventListener("drop", (e) => {
//         e.preventDefault();
//         e.stopPropagation();

//         dropCard.classList.remove("drag-over");

//         const files = e.dataTransfer.files;

//         if (files.length > 0) {
//           const file = files[0];
//           const fileType = file.type;

//           if (
//             fileType === "text/csv" ||
//             fileType ===
//               "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
//           ) {
//             console.log("File accepted:", file.name);

//             const modalId = "upload-form-modal";

//             // Adicionar modal ao DOM, se necessário
//             if (!document.getElementById(modalId)) {
//               const modalMarkup = `
//                   <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}-label" aria-hidden="true">
//                     <div class="modal-dialog modal-dialog-centered modal-lg">
//                       <div class="modal-content">
//                         <div class="modal-header">
//                           <h5 class="modal-title" id="${modalId}-label">Upload Form</h5>
//                           <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
//                         </div>
//                         <div class="modal-body">
//                           <div id="form-container">Loading form...</div>
//                         </div>
//                         <div class="modal-footer">
//                           <a href="#" class="btn btn-secondary" data-bs-dismiss="modal">Close</a>
//                         </div>
//                       </div>
//                     </div>
//                   </div>`;
//               document.body.insertAdjacentHTML("beforeend", modalMarkup);
//             }

//             const modalElement = document.getElementById(modalId);
//             const modal = new bootstrap.Modal(modalElement);

//             // Carregar o formulário via AJAX
//             $.ajax({
//               url: settings.addNewDA.url, // URL do controlador que renderiza o formulário
//               type: "GET",
//               success: function (response) {
//                 if (response.status === "success") {
//                   // Insere o HTML do formulário renderizado no modal
//                   $("#form-container").html(response.form);
//                 } else {
//                   // Exibe uma mensagem de erro no modal
//                   $("#form-container").html(`<p>${response.message}</p>`);
//                 }
//               },
//               error: function (xhr, status, error) {
//                 console.error("Error loading form:", error);
//                 $("#form-container").html("<p>Error loading form.</p>");
//               },
//             });

//             modal.show();
//           } else {
//             alert("Only CSV or XLSX files are allowed.");
//           }
//         }
//       });
//     },
//   };
// })(jQuery, Drupal);

(function ($, Drupal, once) {
    Drupal.behaviors.dragAndDropCard = {
      attach: function (context, settings) {
        // Garante que o comportamento seja anexado apenas uma vez ao elemento
        once('drag-and-drop', '#drop-card', context).forEach(function (dropCard) {
          if (!dropCard) {
            console.error("Drop area not found.");
            return;
          }
  
          console.log("Drop area initialized successfully:", dropCard);
  
          let invalidFileAlertShown = false; // Flag para evitar mensagens duplicadas
  
          const preventDefaultEvents = ["dragenter", "dragover", "dragleave", "drop"];
          preventDefaultEvents.forEach((event) => {
            dropCard.addEventListener(
              event,
              (e) => {
                e.preventDefault();
                e.stopPropagation();
              },
              { passive: false } // Garante que preventDefault seja permitido
            );
          });
  
          dropCard.addEventListener("dragover", () => {
            dropCard.classList.add("drag-over");
          });
  
          dropCard.addEventListener("dragleave", () => {
            dropCard.classList.remove("drag-over");
          });
  
          dropCard.addEventListener("drop", (e) => {
            e.preventDefault();
            e.stopPropagation();
  
            dropCard.classList.remove("drag-over");
  
            const files = e.dataTransfer.files;
  
            if (files.length > 0) {
              const file = files[0];
              let fileType = file.type; // Tipo do arquivo
  
              // Se `type` estiver vazio, tenta determinar pelo nome do arquivo
              if (!fileType) {
                const fileName = file.name.toLowerCase();
                if (fileName.endsWith(".csv")) {
                  fileType = "text/csv";
                } else if (fileName.endsWith(".xlsx")) {
                  fileType =
                    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
                }
              }
  
              // Verifica o tipo de arquivo
              const validFileTypes = [
                "text/csv",
                "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
              ];
  
              if (validFileTypes.includes(fileType)) {
                console.log("File accepted:", file.name);
                invalidFileAlertShown = false; // Reseta o flag quando um arquivo válido é detectado
  
                const modalId = "upload-form-modal";
  
                // Adicionar modal ao DOM, se necessário
                if (!document.getElementById(modalId)) {
                  const modalMarkup = `
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}-label" aria-hidden="true">
                      <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content">
                          <div class="modal-header">
                            <h5 class="modal-title" id="${modalId}-label">Upload Form</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                          </div>
                          <div class="modal-body">
                            <div id="form-container">Loading form...</div>
                          </div>
                          <div class="modal-footer">
                            <a href="#" class="btn btn-secondary" data-bs-dismiss="modal">Close</a>
                          </div>
                        </div>
                      </div>
                    </div>`;
                  document.body.insertAdjacentHTML("beforeend", modalMarkup);
                }
  
                const modalElement = document.getElementById(modalId);
                const modal = new bootstrap.Modal(modalElement);
  
                // Carregar o formulário via AJAX
                $.ajax({
                  url: settings.addNewDA.url, // URL do controlador que renderiza o formulário
                  type: "GET",
                  success: function (response) {
                    if (response.status === "success") {
                      // Insere o HTML do formulário renderizado no modal
                      $("#form-container").html(response.form);
                    } else {
                      // Exibe uma mensagem de erro no modal
                      $("#form-container").html(`<p>${response.message}</p>`);
                    }
                  },
                  error: function (xhr, status, error) {
                    console.error("Error loading form:", error);
                    $("#form-container").html("<p>Error loading form.</p>");
                  },
                });
  
                modal.show();
              } else {
                if (!invalidFileAlertShown) {
                  console.warn("Invalid file type:", fileType || "Unknown type");
                  alert(
                    "Only CSV or XLSX files are allowed. The file you tried to upload is not supported."
                  );
                  invalidFileAlertShown = true; // Ativa o flag para evitar mensagens duplicadas
                }
              }
            }
          });
        });
      },
    };
  })(jQuery, Drupal, once);
  
          