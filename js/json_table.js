(function ($, Drupal, once) {
  Drupal.behaviors.jsonTableLoader = {
    attach: function (context, settings) {
      once("json-table", "#json-table-container", context).forEach(function (
        element
      ) {
        console.log("Initializing JSON table loader...");

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

          console.log("Requesting data from:", url); // Log para depuração

          $.ajax({
            url: url,
            type: "GET",
            success: function (response) {
              console.log("Data received:", response);

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
                  table += "</tr>";
                });

                table += "</tbody></table>";
                $("#json-table-container").html(table);

                // Renderizar a paginação
                renderPagination(response.pagination, page);
              } else {
                $("#json-table-container").html(
                  "<p>No data available to display.</p>"
                );
              }
            },
            error: function (xhr, status, error) {
              console.error("Error loading data:", error);
            },
          });
        };

        // Carregar a primeira página ao inicializar
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
                console.log("Session page updated:", newPage);
              },
              error: function (xhr, status, error) {
                console.error("Error updating session page:", error);
              },
            });
          });
        };

        const initialPage = drupalSettings.std.page || 1;
        loadTableData(initialPage);
      });
    },
  };
})(jQuery, Drupal, once);
