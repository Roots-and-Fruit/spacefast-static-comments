(function () {
  "use strict";

  var MAX_NAME_LENGTH = 40;
  var MAX_BODY_LENGTH = 1000;
  var NAME_STORAGE_KEY = "sf_static_comments:name";
  var POLL_INTERVAL_MS = 15000;

  function formatWhen(iso) {
    var date = new Date(iso);
    if (Number.isNaN(date.valueOf())) return "just now";
    var minutes = Math.round((Date.now() - date.valueOf()) / 60000);
    if (minutes < 1) return "just now";
    if (minutes < 60) return minutes + "m ago";
    if (minutes < 60 * 24) return Math.round(minutes / 60) + "h ago";
    return date.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function cleanBody(value) {
    return String(value || "")
      .replace(/\r\n/g, "\n")
      .split(/\n{2,}/)
      .map(function (paragraph) {
        return paragraph.replace(/\s+/g, " ").trim();
      })
      .filter(function (paragraph) {
        return paragraph !== "";
      })
      .join("\n\n")
      .slice(0, MAX_BODY_LENGTH);
  }

  function mountSection(section) {
    var slug = (section.getAttribute("data-sf-comments-slug") || "").trim();
    var root = section.querySelector("[data-sf-comments-mount]");
    if (!slug || !root) return;

    var headingEl = section.querySelector("[data-sf-comments-heading]");
    var baseWpCount = Number.parseInt(
      (headingEl && headingEl.getAttribute("data-base-count")) ||
        root.getAttribute("data-wp-comment-count") ||
        "0",
      10
    );
    if (Number.isNaN(baseWpCount) || baseWpCount < 0) baseWpCount = 0;

    var state = {
      loading: true,
      unavailable: false,
      requireAuth: false,
      viewer: { userId: "", displayName: "", isAuthenticated: false },
      comments: [],
      name: "",
      body: "",
      pending: false,
      error: ""
    };

    try {
      state.name = window.localStorage.getItem(NAME_STORAGE_KEY) || "";
    } catch (_err) {
      state.name = "";
    }

    var liveRegion = el("div", "sf-comments__live");
    liveRegion.setAttribute("aria-live", "polite");

    var formWrap = el("div", "sf-comments__form-wrap");
    root.innerHTML = "";
    root.appendChild(liveRegion);
    root.appendChild(formWrap);

    function updateHeadingCount() {
      var total = baseWpCount + state.comments.length;
      if (headingEl) {
        if (total > 0) {
          headingEl.textContent = total.toLocaleString() + (total === 1 ? " Comment" : " Comments");
        } else {
          headingEl.textContent = "Comments";
        }
      }
      var bylineLink = document.querySelector("[data-sf-byline-comments]");
      var bylineText = document.querySelector("[data-sf-byline-comments-text]");
      if (bylineLink && bylineText) {
        if (total > 0) {
          bylineText.textContent = total.toLocaleString() + (total === 1 ? " comment" : " comments");
          bylineLink.removeAttribute("hidden");
        } else {
          bylineLink.setAttribute("hidden", "");
        }
      }
    }

    function renderThread() {
      liveRegion.innerHTML = "";
      updateHeadingCount();

      if (state.loading) {
        if (baseWpCount === 0) {
          liveRegion.appendChild(el("p", "sf-comments__state", "Loading comments…"));
        }
        return;
      }

      if (state.unavailable) {
        if (baseWpCount === 0) {
          var isLocal =
            window.location.hostname.endsWith(".local") ||
            window.location.hostname === "localhost" ||
            window.location.hostname === "127.0.0.1";
          liveRegion.appendChild(
            el(
              "p",
              "sf-comments__state",
              isLocal
                ? "Comments run on the Spacefast Zero runtime after publish (or via sf dev)."
                : "New comments are temporarily unavailable."
            )
          );
        }
        return;
      }

      if (state.comments.length === 0) {
        if (baseWpCount === 0) {
          liveRegion.appendChild(
            el("p", "sf-comments__state", "No comments yet. Start the conversation below.")
          );
        }
        return;
      }

      var list = el("ol", "sf-comments__list sf-comments__list--live");
      state.comments.forEach(function (item) {
        var li = el("li", "sf-comments__item sf-comments__item--live");
        var meta = el("header", "sf-comments__meta");
        meta.appendChild(el("span", "sf-comments__author", item.author || "Guest"));

        var time = el("time", "sf-comments__time", formatWhen(item.createdAt));
        if (item.createdAt) time.setAttribute("datetime", item.createdAt);
        meta.appendChild(time);

        if (item.mine) {
          meta.appendChild(el("span", "sf-comments__badge", "you"));
        }

        var bodyP = el("p", "sf-comments__body sf-comments__body--plain", item.body || "");
        li.appendChild(meta);
        li.appendChild(bodyP);
        list.appendChild(li);
      });
      liveRegion.appendChild(list);
    }

    function renderForm() {
      formWrap.innerHTML = "";
      if (state.loading || state.unavailable) return;

      if (state.requireAuth && !state.viewer.isAuthenticated) {
        var authBox = el("div", "sf-comments__auth-gate");
        authBox.appendChild(
          el("p", "sf-comments__state", "Sign in is required to leave a comment on this post.")
        );
        var signInLink = el("a", "sf-comments__submit", "Sign in to comment");
        signInLink.href = "/auth/login?redirect_to=" + encodeURIComponent(window.location.pathname + "#sf-comments");
        authBox.appendChild(signInLink);
        formWrap.appendChild(authBox);
        return;
      }

      var form = el("form", "sf-comments__form");

      var nameField = el("div", "sf-comments__field");
      var nameId = "sf-comment-name-" + slug;
      var nameLabel = el("label", "sf-comments__label", "Name (optional)");
      nameLabel.setAttribute("for", nameId);
      var nameInput = el("input", "sf-comments__input");
      nameInput.id = nameId;
      nameInput.name = "author";
      nameInput.type = "text";
      nameInput.maxLength = MAX_NAME_LENGTH;
      nameInput.autocomplete = "nickname";
      nameInput.placeholder = state.viewer.displayName || "Guest";
      nameInput.value = state.name;
      nameInput.addEventListener("input", function (e) {
        state.name = e.target.value;
      });
      nameField.appendChild(nameLabel);
      nameField.appendChild(nameInput);

      var bodyField = el("div", "sf-comments__field");
      var bodyId = "sf-comment-body-" + slug;
      var bodyLabel = el("label", "sf-comments__label", "Leave a comment");
      bodyLabel.setAttribute("for", bodyId);
      var bodyTextarea = el("textarea", "sf-comments__textarea");
      bodyTextarea.id = bodyId;
      bodyTextarea.name = "body";
      bodyTextarea.required = true;
      bodyTextarea.maxLength = MAX_BODY_LENGTH;
      bodyTextarea.placeholder = "Share your thoughts…";
      bodyTextarea.value = state.body;

      var actions = el("div", "sf-comments__actions");
      var counter = el("span", "sf-comments__counter", MAX_BODY_LENGTH - state.body.length + " characters left");
      var submitBtn = el("button", "sf-comments__submit", state.pending ? "Posting…" : "Post comment");
      submitBtn.type = "submit";
      submitBtn.disabled = state.pending || !cleanBody(state.body);

      bodyTextarea.addEventListener("input", function (e) {
        state.body = e.target.value;
        var remaining = MAX_BODY_LENGTH - state.body.length;
        counter.textContent = remaining + " characters left";
        submitBtn.disabled = state.pending || !cleanBody(state.body);
      });

      bodyField.appendChild(bodyLabel);
      bodyField.appendChild(bodyTextarea);

      actions.appendChild(counter);
      actions.appendChild(submitBtn);

      form.appendChild(nameField);
      form.appendChild(bodyField);
      form.appendChild(actions);

      var errorEl = el("p", "sf-comments__error");
      errorEl.setAttribute("role", "alert");
      if (!state.error) {
        errorEl.hidden = true;
      } else {
        errorEl.textContent = state.error;
      }
      form.appendChild(errorEl);

      var footnote = el(
        "p",
        "sf-comments__footnote",
        state.viewer.isAuthenticated
          ? "Signed in as " + (state.viewer.displayName || "member") + "."
          : "Commenting as a guest. Spam filtering is active."
      );
      form.appendChild(footnote);

      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var cleaned = cleanBody(state.body);
        if (!cleaned || state.pending) return;

        state.pending = true;
        state.error = "";
        submitBtn.disabled = true;
        submitBtn.textContent = "Posting…";
        errorEl.hidden = true;

        try {
          window.localStorage.setItem(NAME_STORAGE_KEY, state.name.trim());
        } catch (_err) {
          // Ignore storage restrictions.
        }

        fetch("/api/comments", {
          method: "POST",
          headers: {
            "content-type": "application/json",
            accept: "application/json"
          },
          credentials: "same-origin",
          body: JSON.stringify({
            post: slug,
            author: state.name,
            body: cleaned
          })
        })
          .then(function (res) {
            return res
              .json()
              .catch(function () {
                return null;
              })
              .then(function (payload) {
                return { ok: res.ok, status: res.status, payload: payload };
              });
          })
          .then(function (result) {
            state.pending = false;
            if (!result.ok || !result.payload || !result.payload.data) {
              var msg =
                (result.payload && result.payload.error && result.payload.error.message) ||
                "Could not post your comment. Please try again.";
              state.error = msg;
              renderForm();
              return;
            }
            state.body = "";
            state.comments.push(result.payload.data);
            renderThread();
            renderForm();
          })
          .catch(function () {
            state.pending = false;
            state.error = "Network error while posting comment. Please try again.";
            renderForm();
          });
      });

      formWrap.appendChild(form);
    }

    function fetchComments(isBackgroundRefresh) {
      fetch("/api/comments?post=" + encodeURIComponent(slug), {
        method: "GET",
        headers: { accept: "application/json" },
        credentials: "same-origin"
      })
        .then(function (res) {
          if (!res.ok) throw new Error("HTTP " + res.status);
          var contentType = res.headers.get("content-type") || "";
          if (contentType.indexOf("application/json") === -1) {
            throw new Error("Non-JSON response");
          }
          return res.json();
        })
        .then(function (payload) {
          if (!payload || !payload.data) throw new Error("Invalid payload");
          var wasUnavailable = state.unavailable;
          var wasLoading = state.loading;
          state.loading = false;
          state.unavailable = false;
          state.requireAuth = Boolean(payload.data.requireAuth);
          state.viewer = payload.data.viewer || state.viewer;
          state.comments = Array.isArray(payload.data.comments) ? payload.data.comments : [];
          renderThread();
          if (!isBackgroundRefresh || wasLoading || wasUnavailable) {
            renderForm();
          }
        })
        .catch(function () {
          if (isBackgroundRefresh) return;
          state.loading = false;
          state.unavailable = true;
          renderThread();
          renderForm();
        });
    }

    renderThread();
    fetchComments(false);

    window.setInterval(function () {
      if (document.visibilityState === "visible" && !state.unavailable && !state.pending) {
        fetchComments(true);
      }
    }, POLL_INTERVAL_MS);
  }

  function init() {
    var sections = document.querySelectorAll("[data-sf-comments-slug]");
    for (var i = 0; i < sections.length; i += 1) {
      mountSection(sections[i]);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
