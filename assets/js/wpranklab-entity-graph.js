(function () {
  if (!window.WPRankLabEntityGraph) return;

  const el = document.getElementById("wpranklab-entity-graph");
  if (!el) return;

  async function fetchData() {
    const params = new URLSearchParams();
    params.set("action", "wpranklab_entity_graph_data");
    params.set("nonce", WPRankLabEntityGraph.nonce);

    const res = await fetch(WPRankLabEntityGraph.ajax, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: params.toString(),
    });

    const json = await res.json();
    if (!json || !json.success) return { nodes: [], edges: [] };
    return json.data || { nodes: [], edges: [] };
  }

  fetchData().then(({ nodes, edges }) => {
    if (!nodes || nodes.length === 0) {
      el.innerHTML = "<p style='padding:12px'>No entities found yet. Run a scan and refresh.</p>";
      return;
    }

    const data = {
      nodes: new vis.DataSet(nodes),
      edges: new vis.DataSet(edges || []),
    };

    const options = {
      interaction: { hover: true },
      physics: { stabilization: true },
      nodes: { shape: "dot" },
      edges: { smooth: true },
    };

    new vis.Network(el, data, options);
  });
})();
