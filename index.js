/* No build step: native Kirby Panel components and Vue's escaped text rendering. */
panel.plugin("timfeskens/traffic", {
  components: {
    "timfeskens-traffic-view": {
      props: { report: Object },
      methods: {
        refresh() {
          // Fetch a fresh server-rendered report, not a cached Panel view.
          window.location.reload();
        },
        table(h, title, columns, rows) {
          return h("section", { class: "traffic-section" }, [
            h("h2", title),
            rows.length ? h("div", { class: "traffic-table-scroll", attrs: { tabindex: "0", role: "region", "aria-label": title } }, [
              h("table", [
                h("thead", [h("tr", columns.map(([key, label]) => h("th", { attrs: { scope: "col" } }, label)))]),
                h("tbody", rows.map(row => h("tr", columns.map(([key]) => h("td", String(row[key] ?? "—") || "—"))))),
              ]),
            ]) : h("p", { class: "traffic-muted" }, "Nog geen gegevens in deze periode."),
          ]);
        },
      },
      render(h) {
        const r = this.report;
        return h("k-panel-inside", { class: "timfeskens-traffic-view" }, [
          h("k-header", "Verkeer"),
          h("k-button", { props: { icon: "refresh" }, on: { click: this.refresh } }, "Vernieuwen"),
          h("nav", { class: "traffic-period", attrs: { "aria-label": "Rapportperiode" } }, [7, 30, 90].map(days =>
            h("k-link", { props: { to: `traffic/${days}` }, attrs: { "aria-current": r.days === days ? "page" : null } }, `Laatste ${days} dagen`))),
          h("p", { class: "traffic-intro" }, `Paginaweergaven, geen unieke bezoekers. Zonder cookies, IP-adressen of bezoekersprofielen. Tijdzone: ${r.timezone}.`),
          h("p", { class: "traffic-intro" }, "Je eigen bezoeken tellen niet mee zolang je in dit browserprofiel bent ingelogd in het Panel. Test een UTM-link in een privévenster zonder Panel-sessie en vernieuw daarna dit rapport. Ook Do Not Track en Global Privacy Control worden gerespecteerd."),
          r.error ? h("p", { class: "traffic-warning", attrs: { role: "status" } }, "Verkeersgegevens zijn tijdelijk niet beschikbaar. Controleer de opslag en SQLite-ondersteuning.") : null,
          h("div", { class: "traffic-stats" }, [
            h("div", [h("strong", Number(r.total).toLocaleString("nl-NL")), h("span", "Paginaweergaven")]),
            h("div", [h("strong", Number(r.tagged).toLocaleString("nl-NL")), h("span", "Met UTM-campagne")]),
          ]),
          !r.total && !r.error ? h("p", { class: "traffic-empty" }, "Zodra bezoekers binnenkomen op de ingestelde hosts, verschijnen hier de bronnen. Lokale bezoeken, ingelogde redacteuren, bekende bots en verzoeken met Do Not Track of Global Privacy Control tellen niet mee.") : null,
          this.table(h, "Bronnen & media", [["source", "Bron"], ["medium", "Medium"], ["views", "Weergaven"]], r.sources),
          this.table(h, "UTM-campagnes & landingspagina’s", [["campaign", "Campagne"], ["source", "Bron"], ["medium", "Medium"], ["path", "Landingspagina"], ["views", "Weergaven"]], r.campaigns),
          this.table(h, "Verwijzende websites", [["referrer", "Website"], ["views", "Weergaven"]], r.referrers),
          this.table(h, "Pagina’s", [["path", "Pagina"], ["views", "Weergaven"]], r.pages),
          this.table(h, "Per dag", [["day", "Datum"], ["views", "Weergaven"]], r.daily),
          h("p", { class: "traffic-intro" }, "UTM-labels gelden alleen voor de geopende URL; ze worden niet bij volgende pagina’s bewaard. (direct) = geen herkenbare bron, (intern) = interne link. Maximaal 100 rijen per tabel; dagtotalen blijven volledig binnen de opslaglimiet. Bewaartermijn: 180 dagen. Bekende bots worden gefilterd; tellingen blijven indicatief."),
        ]);
      },
    },
  },
});
