#!/usr/bin/env python3
"""Prépare un épisode Spotify pour la page Médias, affiché comme les cartes
« partager en story » (dessinées en CSS par le shortcode medias).

1. Ouvrir l'épisode sur open.spotify.com, onglet Réseau des outils dev, puis
   « Enregistrer tout au format HAR ».
2. python3 scripts/spotify-story.py <fichier.har> <url de l'épisode> static/images/medias/podcasts-NN.jpg

Télécharge la pochette et affiche l'entrée à coller dans data/medias.yaml.
"""
import json, os, re, subprocess, sys, urllib.request

har, episode, out = sys.argv[1:4]
episode = re.search(r"([A-Za-z0-9]{22})", episode).group(1)

ep = None
for e in json.load(open(har))["log"]["entries"]:
    text = e["response"]["content"].get("text") or ""
    if f"spotify:episode:{episode}" in text and "episodeUnionV2" in text:
        ep = json.loads(text)["data"]["episodeUnionV2"]
        break
if not ep:
    sys.exit("Épisode introuvable dans le HAR (recharger la page de l'épisode avant d'exporter).")

cover = max(ep["coverArt"]["sources"], key=lambda s: s["width"])["url"]
urllib.request.urlretrieve(cover, out)
subprocess.run(["sips", "-Z", "360", out], check=True, capture_output=True)

# minContrast.backgroundBase = fond des cartes story Spotify (vérifié sur les vignettes existantes)
c = ep["visualIdentity"]["squareCoverImage"]["extractedColorSet"]["minContrast"]["backgroundBase"]
print(f'''  - titre: "{ep["name"]}"
    auteur: "{ep["podcastV2"]["data"]["name"]}"
    lien: "https://open.spotify.com/episode/{episode}"
    image: /images/medias/{os.path.basename(out)}
    couleur: "#{c["red"]:02x}{c["green"]:02x}{c["blue"]:02x}"''')
