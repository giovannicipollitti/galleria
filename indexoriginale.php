<?php
// gallery.php
// Config
$imagesDir = __DIR__ . '/images';
$webBase   = 'images'; // percorso web relativo (cartella radice)
$allowed   = ['jpg','jpeg','png','gif','webp','avif'];

// Parametri di ordinamento ?sort=name|date&order=asc|desc
$sort  = $_GET['sort']  ?? 'name';
$order = $_GET['order'] ?? 'asc';

// Filtro cartella (relativa alla radice images/) ?folder=nome/sottocartella
$requestedFolder = $_GET['folder'] ?? '';
$requestedFolder = trim(str_replace('\\','/', $requestedFolder), '/'); // normalizza

if (!is_dir($imagesDir)) {
  http_response_code(500);
  echo "<h2>Errore: la cartella <code>images/</code> non esiste.</h2>";
  exit;
}

// Utility: URL sicuro per path relativo (multi-cartella)
function url_from_relpath(string $rel): string {
  $parts = preg_split('~[\\\\/]+~', $rel, -1, PREG_SPLIT_NO_EMPTY);
  return implode('/', array_map('rawurlencode', $parts));
}

// Raccoglie ricorsivamente i file immagine
$files = [];
$it = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($imagesDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $fileinfo) {
  if (!$fileinfo->isFile()) continue;
  $ext = strtolower($fileinfo->getExtension());
  if (!in_array($ext, $allowed)) continue;

  $abs   = $fileinfo->getPathname();
  $rel   = ltrim(str_replace($imagesDir, '', $abs), DIRECTORY_SEPARATOR);
  $rel   = str_replace('\\','/',$rel);
  $mtime = $fileinfo->getMTime();
  $dir   = trim(str_replace('\\','/', dirname($rel)), '/');
  if ($dir === '.') $dir = ''; // radice

  $files[] = ['rel'=>$rel, 'dir'=>$dir, 'mtime'=>$mtime];
}

// Costruisce elenco cartelle (solo quelle che contengono almeno un'immagine)
$folders = [];
foreach ($files as $item) {
  $folders[$item['dir']] = true; // '' = radice (non la mostriamo nel menu)
}
unset($folders['']); // togli radice dalla lista menu
$folders = array_keys($folders);
usort($folders, fn($a,$b)=>strnatcasecmp($a,$b));

// Valida folder richiesta: deve esistere tra quelle trovate
if ($requestedFolder !== '' && !in_array($requestedFolder, $folders, true)) {
  $requestedFolder = ''; // fallback se non valida
}

// Applica filtro: mostra tutte o solo quelle della cartella selezionata (non ricorsivo)
$visible = array_values(array_filter($files, function($f) use ($requestedFolder){
  if ($requestedFolder === '') return true;
  return $f['dir'] === $requestedFolder; // esatta, non include sottolivelli
}));

// Ordina
usort($visible, function($a, $b) use ($sort, $order){
  if ($sort === 'date') {
    $cmp = $a['mtime'] <=> $b['mtime'];
  } else {
    $cmp = strnatcasecmp($a['rel'], $b['rel']);
  }
  return ($order === 'asc') ? $cmp : -$cmp;
});

$count = count($visible);

// Helper per preservare parametri in link di ordinamento
function qs_with(array $merge): string {
  $curr = $_GET;
  foreach ($merge as $k=>$v) {
    if ($v === null) unset($curr[$k]); else $curr[$k] = $v;
  }
  return http_build_query($curr);
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Galleria verticale</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root { --maxw: 900px; --bar:#151515; --bg:#111; --fg:#eee; --mut:#bbb; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:var(--bg); color:var(--fg); }

    /* Header */
    header {
      position: sticky; top: 0; z-index: 3;
      background: #111c; backdrop-filter: blur(6px);
      border-bottom: 1px solid #333; padding: .6rem 1rem;
      display:flex; align-items:center; gap:.8rem;
    }
    .wrap { max-width: var(--maxw); margin: 0 auto; width:100%; display:flex; align-items:center; gap:.8rem; }
    .brand { font-weight:700; }
    .muted { color: var(--mut); font-size:.95rem; }
    .spacer { flex:1 }
    a, a:visited { color:#9fd1ff; text-decoration: none; }
    a:hover { text-decoration: underline; }

    /* Hamburger */
    .hamb { border:none; background:#232323; color:var(--fg); width:40px; height:40px; border-radius:10px; cursor:pointer; display:grid; place-items:center; }
    .hamb:hover { background:#2e2e2e; }
    .bars{display:block;width:20px;height:2px;background:var(--fg); position:relative;}
    .bars::before,.bars::after{content:"";position:absolute;left:0;width:20px;height:2px;background:var(--fg);}
    .bars::before{top:-6px}.bars::after{top:6px}

    /* Sidebar */
    .drawer {
      position: fixed; inset:0 auto 0 0; width: 280px; background:#121212; border-right:1px solid #2a2a2a;
      transform: translateX(-100%); transition: transform .25s ease; z-index: 4;
      display:flex; flex-direction:column; max-width:90vw;
    }
    .drawer.open { transform: translateX(0); }
    .drawer header { position: static; border-bottom:1px solid #2a2a2a; padding:.9rem 1rem; }
    .menu {
      overflow:auto; padding: .5rem .5rem 1rem;
    }
    .item {
      display:flex; align-items:center; gap:.5rem;
      padding:.5rem .7rem; border-radius:10px; color:#ddd; text-decoration:none;
    }
    .item:hover { background:#1d1d1d; text-decoration:none; }
    .item.active { background:#273445; color:#e8f3ff; }
    .pill { margin-left:auto; font-size:.8rem; color:#ccc; }

    main { max-width: var(--maxw); margin: 1rem auto 3rem; padding: 0 1rem; }
    figure { margin: 0 0 1rem; background:#181818; border:1px solid #2a2a2a; border-radius:12px; overflow:hidden; }
    img { display:block; width:100%; height:auto; }
    .bar {
      display:flex; justify-content:flex-end; gap:.5rem;
      padding:.6rem .8rem; border-top:1px solid #2a2a2a; background:var(--bar);
    }
    .btn {
      border:1px solid #3a3a3a; background:#232323; color:#eee;
      padding:.45rem .8rem; border-radius:999px; cursor:pointer; text-decoration:none; font-size:.9rem;
    }
    .btn:hover { background:#2e2e2e; }
    .top { position: fixed; right: 12px; bottom: 12px; z-index: 2; }
    .top button {
      border:none; background:#2a2a2a; color:#eee; padding:.6rem .8rem; border-radius:999px; cursor:pointer;
      box-shadow: 0 6px 20px #0008;
    }
    .top button:hover { background:#3a3a3a; }

    /* Overlay quando il menu è aperto su mobile */
    .scrim {
      position: fixed; inset:0; background:#0009; opacity:0; pointer-events:none; transition:opacity .25s ease; z-index: 3;
    }
    .scrim.show { opacity:1; pointer-events:auto; }
  </style>
</head>
<body>
  <!-- Sidebar -->
  <aside id="drawer" class="drawer" aria-hidden="true">
    <header><strong>Cartelle</strong></header>
    <nav class="menu">
      <?php
        // Conta immagini per cartella
        $counts = [];
        foreach ($files as $it) {
          $d = $it['dir'];
          $counts[$d] = ($counts[$d] ?? 0) + 1;
        }
        // Radice: "Tutte le foto"
        $isActive = ($requestedFolder==='');
        $qs = qs_with(['folder'=>null]); // rimuove il filtro
        echo '<a class="item '.($isActive?'active':'').'" href="?'.$qs.'">📁 Tutte le foto <span class="pill">'.$count.'</span></a>';

        foreach ($folders as $f) {
          $active = ($f === $requestedFolder);
          $qs = qs_with(['folder'=>$f]);
          $label = htmlspecialchars($f);
          $c = $counts[$f] ?? 0;
          echo '<a class="item '.($active?'active':'').'" href="?'.$qs.'">📂 '.$label.' <span class="pill">'.$c.'</span></a>';
        }
      ?>
    </nav>
  </aside>
  <div id="scrim" class="scrim" role="presentation"></div>

  <!-- Header -->
  <header>
    <div class="wrap">
      <button id="hamb" class="hamb" aria-label="Apri menu" aria-expanded="false" aria-controls="drawer"><span class="bars"></span></button>
      <div class="brand">Galleria verticale</div>
      <div class="muted">— <?= $count ?> immagine<?= $count===1?'':'i' ?><?= $requestedFolder!=='' ? ' in /'.htmlspecialchars($requestedFolder) : '' ?></div>
      <div class="spacer"></div>
      <div class="muted">
        Ordina:
        <a href="?<?= qs_with(['sort'=>'name','order'=>'asc']) ?>">Nome ↑</a> ·
        <a href="?<?= qs_with(['sort'=>'name','order'=>'desc']) ?>">Nome ↓</a> ·
        <a href="?<?= qs_with(['sort'=>'date','order'=>'desc']) ?>">Più recenti</a> ·
        <a href="?<?= qs_with(['sort'=>'date','order'=>'asc']) ?>">Più vecchie</a>
      </div>
    </div>
  </header>

  <!-- Lista immagini -->
  <main>
    <?php if (!$visible): ?>
      <p>Nessuna immagine trovata<?= $requestedFolder!=='' ? ' in <code>/'.htmlspecialchars($requestedFolder).'</code>' : '' ?>.</p>
    <?php else: ?>
      <?php foreach ($visible as $item): ?>
        <?php
          $rel    = $item['rel'];
          $urlRel = url_from_relpath($rel);
          $src    = $webBase . '/' . $urlRel;
          $fname  = basename($rel);
        ?>
        <figure>
          <img src="<?= htmlspecialchars($src) ?>" alt="" loading="lazy" decoding="async" />
          <div class="bar">
            <a class="btn" href="<?= htmlspecialchars($src) ?>" download="<?= htmlspecialchars($fname) ?>">Scarica</a>
          </div>
        </figure>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

  <div class="top"><button onclick="window.scrollTo({top:0, behavior:'smooth'})">Torna su ↑</button></div>

  <script>
    (function(){
      const drawer = document.getElementById('drawer');
      const scrim  = document.getElementById('scrim');
      const hamb   = document.getElementById('hamb');

      function openDrawer(){
        drawer.classList.add('open');
        scrim.classList.add('show');
        hamb.setAttribute('aria-expanded','true');
      }
      function closeDrawer(){
        drawer.classList.remove('open');
        scrim.classList.remove('show');
        hamb.setAttribute('aria-expanded','false');
      }
      hamb.addEventListener('click', ()=>{
        if (drawer.classList.contains('open')) closeDrawer(); else openDrawer();
      });
      scrim.addEventListener('click', closeDrawer);
      // Chiudi con ESC
      document.addEventListener('keydown', (e)=>{
        if (e.key === 'Escape') closeDrawer();
      });
    })();
  </script>
</body>
</html>
