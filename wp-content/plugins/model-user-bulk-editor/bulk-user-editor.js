jQuery(function ($) {
  $('select[name="action"], select[name="action2"]').append(
    $("<option>", {
      value: "edit_user_model",
      text: "Назначить/Удалить операторов",
    })
  );

  $(document).on("click", "#doaction, #doaction2", function (e) {
    const action = $(this)
      .closest(".actions")
      .find('select[name^="action"]')
      .val();
    if (action !== "edit_user_model") return;

    e.preventDefault();

    const post_ids = $('input[name="post[]"]:checked')
      .map(function () {
        return $(this).val();
      })
      .get();

    if (!post_ids.length) return alert("Выберите хотя бы одну модель");

    $.post(
      BULK_USER_MODEL.ajaxurl,
      {
        action: "get_bulk_user_model_modal",
        nonce: BULK_USER_MODEL.nonce,
        post_ids: post_ids,
      },
      function (res) {
        if (res.success) {
          $("#bulk-user-model-modal").html(res.data).fadeIn();
        } else {
          alert(res.data);
        }
      }
    );
  });

  $(document).on("input", ".user-autocomplete", function () {
    const $input = $(this);
    const term = $input.val();
    const postId = $input.data("post-id");

    if (term.length < 2) return;

    $.get(
      BULK_USER_MODEL.ajaxurl,
      {
        action: "user_autocomplete_search",
        term: term,
      },
      function (users) {
        const $suggest = $('<ul class="autocomplete-suggestions">')
          .css({
            position: "absolute",
            background: "#fff",
            border: "1px solid #ccc",
            zIndex: 99999,
            padding: "5px",
            margin: 0,
            listStyle: "none",
            width: $input.outerWidth(),
          })
          .insertAfter($input)
          .empty();

        users.forEach((user) => {
          $("<li>")
            .text(user.label)
            .css({ cursor: "pointer", padding: "2px 5px" })
            .on("click", function () {
              const $container = $(
                '.selected-users[data-post-id="' + postId + '"]'
              );
              if (
                $container.find('input[value="' + user.user_id + '"]')
                  .length === 0
              ) {
                $("<span>")
                  .html(
                    user.label +
                      ' <span class="remove-user">×</span>' +
                      '<input type="hidden" name="users[' +
                      postId +
                      '][]" value="' +
                      user.user_id +
                      '">'
                  )
                  .attr("data-id", user.user_id)
                  .appendTo($container);
              }
              $suggest.remove();
              $input.val("");
            })
            .appendTo($suggest);
        });

        $(document).one("click", function () {
          $suggest.remove();
        });
      }
    );
  });

  $(document).on("click", ".remove-user", function () {
    $(this).closest("span[data-id]").remove();
  });

  $(document).on("submit", "#bulk-user-model-form", function (e) {
    e.preventDefault();
    $.post(BULK_USER_MODEL.ajaxurl, $(this).serialize(), function (res) {
      if (res.success) {
        alert("Сохранено");
        location.reload();
      } else {
        alert(res.data || "Ошибка");
      }
    });
  });

  // Обработчик закрытия модального окна
  $(document).on("click", ".modal-close", function () {
    $("#bulk-user-model-modal").fadeOut().empty();
  });
});
