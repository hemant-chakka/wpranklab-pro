/* global wp, ajaxurl */
(function () {
  if (!wp || !wp.plugins || !wp.editPost) return;

  const { registerPlugin } = wp.plugins;
  const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
  const { PanelBody, Spinner, Notice } = wp.components;
  const { createElement: el, useEffect, useState } = wp.element;
  const { select } = wp.data;

  function SidebarContent() {
    const [html, setHtml] = useState("");
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    const postId = select("core/editor").getCurrentPostId();
    const postType = select("core/editor").getCurrentPostType();

    useEffect(() => {
      let cancelled = false;

      async function load() {
        setLoading(true);
        setError("");

        try {
          const form = new window.FormData();
          form.append("action", "wpranklab_render_editor_panel");
          form.append("post_id", String(postId));
          form.append("post_type", String(postType));
          // nonce is optional but recommended if you already have a nonce system:
          if (window.WPRankLabEditor && window.WPRankLabEditor.nonce) {
            form.append("_ajax_nonce", window.WPRankLabEditor.nonce);
          }

          const res = await fetch(ajaxurl, {
            method: "POST",
            credentials: "same-origin",
            body: form,
          });

          const json = await res.json();
          if (cancelled) return;

          if (!json || !json.success) {
            setError((json && json.data && json.data.message) ? json.data.message : "Failed to load panel.");
            setHtml("");
          } else {
            setHtml(json.data.html || "");
          }
        } catch (e) {
          if (cancelled) return;
          setError(e && e.message ? e.message : "Failed to load panel.");
          setHtml("");
        } finally {
          if (!cancelled) setLoading(false);
        }
      }

      if (postId) load();

      return () => { cancelled = true; };
    }, [postId]);

    if (loading) {
      return el("div", { style: { padding: "12px" } }, el(Spinner, null));
    }

    if (error) {
      return el("div", { style: { padding: "12px" } },
        el(Notice, { status: "error", isDismissible: false }, error)
      );
    }

    // Render your existing PHP UI safely
    return el("div", {
      style: { padding: "12px" },
      dangerouslySetInnerHTML: { __html: html }
    });
  }

  registerPlugin("wpranklab-sidebar", {
    render: function () {
      return el(
        wp.element.Fragment,
        null,
        el(
          PluginSidebarMoreMenuItem,
          { target: "wpranklab-sidebar" },
          "WPRankLab"
        ),
        el(
			PluginSidebar, {
			  name: "wpranklab-sidebar-v1",
			  title: "WPRankLab",
			  icon: "visibility"
			},
          el(PanelBody, { title: "AI Visibility", initialOpen: true }, el(SidebarContent))
        )
      );
    },
  });
})();
