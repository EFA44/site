// Page Médias (shortcode medias) : lecteur intégré, nouveautés et dates limites
(() => {
  const dialog = document.querySelector(".media-player");
  const iframe = dialog.querySelector("iframe");
  document.addEventListener("click", (e) => {
    const link = e.target.closest("a[data-embed]");
    if (!link || !dialog.showModal) return;
    e.preventDefault();
    iframe.src = link.dataset.embed;
    dialog.showModal();
  });
  // Clic sur le fond : fermer ; à la fermeture, vider l'iframe pour couper le son
  dialog.addEventListener("click", (e) => { if (e.target === dialog) dialog.close(); });
  dialog.addEventListener("close", () => { iframe.src = "about:blank"; });
})();

// Nouveautés : éléments ajoutés (champ ajout) il y a moins de 3 semaines
document.addEventListener("DOMContentLoaded", () => {
  const jour = (decalage = 0) => new Date(Date.now() + decalage * 864e5).toISOString().slice(0, 10);
  // ?date=AAAA-MM-JJ dans l'URL pour vérifier les étiquettes à une autre date
  const today = new URLSearchParams(location.search).get("date") || jour();
  const limite = jour(-21);

  // Date limite (champ fin) : compte à rebours dans les 30 derniers jours ;
  // une fois passée, la vignette n'est plus un lien et la date disparaît
  document.querySelectorAll(".media-item[data-fin]").forEach((el) => {
    const reste = Math.round((new Date(el.dataset.fin) - new Date(today)) / 864e5);
    if (reste < 0) {
      const div = document.createElement("div");
      div.className = el.className;
      if (el.dataset.ajout) div.dataset.ajout = el.dataset.ajout;
      el.querySelector(".media-item__fin").remove();
      div.append(...el.childNodes);
      el.replaceWith(div);
    }
    else if (reste <= 30) el.dataset.reste = reste ? `Plus que ${reste} j` : "Dernier jour";
  });

  const nouveaux = [...document.querySelectorAll(".media-item[data-ajout]")].filter((el) => el.dataset.ajout > limite);
  if (!nouveaux.length) return;
  nouveaux.forEach((el) => el.classList.add("is-new"));

  const grids = [...document.querySelectorAll(".media-grid")];
  const button = document.createElement("button");
  button.className = "media-filter";
  button.setAttribute("aria-pressed", "false");
  const label = () => button.textContent = button.getAttribute("aria-pressed") === "true"
    ? "Tout afficher" : `Nouveautés (${nouveaux.length})`;
  label();
  button.addEventListener("click", () => {
    const on = button.getAttribute("aria-pressed") !== "true";
    button.setAttribute("aria-pressed", on);
    label();
    grids.forEach((grid) => {
      grid.querySelectorAll(".media-item").forEach((el) => el.hidden = on && !el.classList.contains("is-new"));
      // Masquer aussi l'intertitre des catégories sans nouveauté
      const vide = on && !grid.querySelector(".media-item.is-new");
      grid.hidden = vide;
      if (grid.previousElementSibling?.tagName === "H2") grid.previousElementSibling.hidden = vide;
    });
  });
  // Sous « Sommaire » dans le menu de gauche ; au-dessus des catégories quand il est masqué (mobile)
  const tocTitle = document.querySelector(".toc .toc-title");
  if (tocTitle?.offsetParent) tocTitle.after(button);
  else (grids[0].previousElementSibling?.tagName === "H2" ? grids[0].previousElementSibling : grids[0]).before(button);
});
