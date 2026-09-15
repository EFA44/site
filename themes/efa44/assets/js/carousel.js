/* Carrousel média — défilement auto, mis en pause pendant la lecture d'une vidéo.
   Vidéos HTML5 (<video>) : événements natifs play/pause/ended.
   YouTube : API officielle IFrame Player (onStateChange). */
(function () {
  'use strict';

  // --- Chargement unique de l'API YouTube IFrame ---
  var ytApiReady = false;
  var ytCallbacks = [];
  function whenYTReady(cb) {
    if (ytApiReady && window.YT && window.YT.Player) { cb(); return; }
    ytCallbacks.push(cb);
    if (document.getElementById('youtube-iframe-api')) return;
    var tag = document.createElement('script');
    tag.id = 'youtube-iframe-api';
    tag.src = 'https://www.youtube.com/iframe_api';
    document.head.appendChild(tag);
  }
  window.onYouTubeIframeAPIReady = function () {
    ytApiReady = true;
    ytCallbacks.forEach(function (cb) { cb(); });
    ytCallbacks = [];
  };

  var uid = 0;

  function initCarousel(root) {
    var track = root.querySelector('.carousel__track');
    var slides = Array.prototype.slice.call(root.querySelectorAll('.carousel__slide'));
    if (!track || slides.length === 0) return;

    var dots = Array.prototype.slice.call(root.querySelectorAll('[data-carousel-dot]'));

    var autoplay = root.getAttribute('data-autoplay') !== 'false';
    var interval = parseInt(root.getAttribute('data-interval'), 10) || 8000;

    var current = 0;
    var timer = null;
    var isPlayingMedia = false;
    var ytPlayers = {}; // index -> YT.Player

    // Chargement paresseux : l'iframe YouTube n'est chargée (et son lecteur API
    // instancié) que lorsque son slide devient actif.
    function lazyLoadSlide(index) {
      var slide = slides[index];
      var iframe = slide.querySelector('iframe[data-src]');
      if (!iframe) return;
      if (!iframe.id) { iframe.id = 'carousel-yt-' + (++uid); }
      iframe.src = iframe.getAttribute('data-src');
      iframe.removeAttribute('data-src');
      whenYTReady(function () {
        ytPlayers[index] = new YT.Player(iframe.id, {
          events: {
            onStateChange: function (e) {
              // 1 = playing, 2 = paused, 0 = ended
              if (e.data === 1) { onMediaPlay(); }
              else if (e.data === 2 || e.data === 0) { onMediaStop(); }
            }
          }
        });
      });
    }

    function show(index) {
      current = (index + slides.length) % slides.length;
      slides.forEach(function (slide, i) {
        var active = i === current;
        slide.classList.toggle('is-active', active);
        if (active) { slide.removeAttribute('aria-hidden'); }
        else { slide.setAttribute('aria-hidden', 'true'); }
      });
      dots.forEach(function (dot, i) {
        dot.classList.toggle('is-active', i === current);
      });
      stopAllMedia();
      lazyLoadSlide(current);
    }

    function next() { show(current + 1); }

    function startAuto() {
      if (!autoplay || slides.length < 2 || isPlayingMedia) return;
      stopAuto();
      timer = setInterval(next, interval);
    }
    function stopAuto() {
      if (timer) { clearInterval(timer); timer = null; }
    }

    // --- Contrôle des médias ---
    function stopAllMedia() {
      root.querySelectorAll('video.carousel__video').forEach(function (v) {
        try { v.pause(); } catch (e) {}
      });
      Object.keys(ytPlayers).forEach(function (k) {
        var p = ytPlayers[k];
        try { if (p && p.pauseVideo) p.pauseVideo(); } catch (e) {}
      });
    }

    function onMediaPlay() {
      isPlayingMedia = true;
      stopAuto();
    }
    function onMediaStop() {
      isPlayingMedia = false;
      startAuto();
    }

    // Vidéos HTML5
    root.querySelectorAll('video.carousel__video').forEach(function (v) {
      v.addEventListener('play', onMediaPlay);
      v.addEventListener('playing', onMediaPlay);
      v.addEventListener('pause', onMediaStop);
      v.addEventListener('ended', onMediaStop);
    });

    // --- Navigation manuelle (points) ---
    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        show(parseInt(dot.getAttribute('data-carousel-dot'), 10));
        startAuto();
      });
    });

    // Pause au survol (confort de lecture), reprise à la sortie
    root.addEventListener('mouseenter', stopAuto);
    root.addEventListener('mouseleave', startAuto);

    show(0);
    startAuto();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-carousel]').forEach(initCarousel);
  });
})();
