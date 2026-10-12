#!/usr/bin/env python3
"""Télécharge l'affiche (portrait) d'un film, d'une série ou d'un téléfilm
depuis IMDb pour la page Médias.

France TV et Arte ne fournissent que des visuels 16:9, alors que les vignettes
de la page sont en 9:16 : IMDb a les vraies affiches (pas les reportages).

    python3 scripts/affiche.py "Retour à Séoul" static/images/medias/films-02.jpg

Affiche les résultats trouvés et télécharge le premier qui a une image ;
passer le numéro du résultat en 3e argument si ce n'est pas le bon.
"""
import json, sys, urllib.parse, urllib.request

titre, out = sys.argv[1:3]
choix = int(sys.argv[3]) if len(sys.argv) > 3 else 0


def get(url):
    return urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})).read()


# Endpoint de la barre de recherche d'IMDb, public et sans clé
url = "https://v2.sg.media-imdb.com/suggestion/x/" + urllib.parse.quote(titre.lower()) + ".json"
resultats = [r for r in json.loads(get(url)).get("d", []) if r.get("i") and r.get("q")]
if not resultats:
    sys.exit("Aucune affiche trouvée.")
for i, r in enumerate(resultats):
    print(i, r["l"], r.get("y", ""), r["q"], f'{r["i"]["width"]}×{r["i"]["height"]}')

# Suffixe d'URL Amazon : largeur 500 px
image = resultats[choix]["i"]["imageUrl"].replace("._V1_.", "._V1_SX500.")
with open(out, "wb") as f:
    f.write(get(image))
print(f"→ {out} : {resultats[choix]['l']}")
