<?php
/**
 * index.php -- public landing page.
 *
 * Self-contained (it does not use includes/header.php) because the public
 * page has its own left sidebar navigation and marketing sections. Logged-in
 * users are sent straight to their dashboard. Helpers come from init.php.
 */
require_once __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect(dashboard_path(current_user()['role']));
}

$f = flash();

/* ---- content (illustrative data for the prototype landing page) ------ */
$services = [
    ['stetho',   'General Medicine', 'Everyday check-ups, illness and follow-up care for adults.'],
    ['child',    'Paediatrics',      'Growth checks, immunisation and care for babies and children.'],
    ['heart',    'Maternity',        'Antenatal visits and support before and after childbirth.'],
    ['scissors', 'Surgery',          'Assessment and referral for minor and planned procedures.'],
    ['eye',      'Eye Clinic',       'Vision tests and treatment for common eye conditions.'],
    ['tooth',    'Dental',           'Tooth pain, extractions and routine dental care.'],
    ['brain',    'Mental Health',    'Confidential counselling and mental-health support.'],
    ['flask',    'Laboratory',       'Sample collection and test results linked to your visit.'],
];

$doctors = [
    ['Dr Grace Banda',   'General Medicine'],
    ['Dr Michael Phiri', 'Paediatrics'],
    ['Dr Sarah Mwale',   'Maternity'],
    ['Dr Joseph Zulu',   'Surgery'],
    ['Dr Esther Tembo',  'Mental Health'],
];

$reviews = [
    ['The nurse booked me from the health post and I only travelled once the doctor confirmed. I did not waste a whole day waiting.', 'Agness M.', 'Patient, Lufwanyama'],
    ['I can see who is coming the next day and read their notes before they arrive, so I am ready for them.', 'J. Mulenga', 'Health worker'],
    ['Even when the network dropped, my request was saved and sent later. Nothing was lost.', 'David K.', 'Patient, Mkushi road'],
];

$faqs = [
    ['How do I book an appointment?', 'Create a free account and choose your health centre, a doctor and a date. If you have no smartphone, a health worker at your clinic can book for you in the same way.'],
    ['Do I have to travel before I know a doctor is available?', 'No. That is the point of the system. You only travel once your appointment status reads <strong>Confirmed</strong> or <strong>Rescheduled</strong>.'],
    ['What happens if the network or power goes down while I am booking?', 'Your entry is saved on your phone and sent automatically when the connection returns, so you never have to start again.'],
    ['Can a specialist see my case if they are not at the centre that day?', 'Yes. Your health worker records your clinical notes and forwards them, and the doctor reviews your case and replies with a date or advice.'],
    ['Is my health information private?', 'Yes. Access is limited to you and the clinical staff involved in your care. Passwords are stored only as secure hashes and data is sent over an encrypted connection.'],
];

/* sidebar / section navigation (anchor links on this page) */
$nav = [
    ['#top',      'Home',         'home'],
    ['#services', 'Services',     'pulse'],
    ['#about',    'About',        'shield'],
    ['#doctors',  'Our Doctors',  'users'],
    ['#book',     'Book a Visit', 'calendar'],
    ['#reviews',  'Testimonials', 'chat'],
    ['#faq',      'FAQ',          'clipboard'],
    ['#contact',  'Contact',      'mappin'],
];

$EMERGENCY = '+260 212 000 000';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#10785a">
<meta name="description" content="Lufwanyama District Telemedicine — book an appointment at your rural health centre and let a doctor review your case before you travel. Built to work on a weak signal.">
<title>Lufwanyama Telemedicine &middot; Your health, close to home</title>
<link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(url('/assets/css/landing.css')) ?>">
<link rel="icon" href="data:,">
</head>
<body class="lp" id="top">
<a class="skip" href="#main">Skip to content</a>

<!-- ===== left sidebar (desktop) ===== -->
<aside class="lp-side" aria-label="Primary">
  <a class="lp-brand" href="#top">
    <span class="lp-brand__mark"><?= icon('cross') ?></span>
    <span>
      <span class="lp-brand__name">Lufwanyama</span>
      <span class="lp-brand__sub">Telemedicine</span>
    </span>
  </a>

  <nav class="lp-nav" aria-label="Sections">
<?php foreach ($nav as [$href, $label, $ico]): ?>
    <a href="<?= e($href) ?>" data-spy="<?= e(ltrim($href, '#')) ?>"<?= $href === '#top' ? ' class="on"' : '' ?>><?= icon($ico) ?><span><?= e($label) ?></span></a>
<?php endforeach; ?>
  </nav>

  <div class="lp-side__cta">
    <a class="b b-primary b-wide" href="<?= e(url('/register.php')) ?>"><?= icon('userplus') ?>Register</a>
    <a class="b b-light b-wide" href="<?= e(url('/login.php')) ?>"><?= icon('login') ?>Login</a>
  </div>

  <div class="lp-side__foot">
    <div>24/7 emergency line</div>
    <div class="row"><?= icon('phone') ?><b><?= e($EMERGENCY) ?></b></div>
  </div>
</aside>

<!-- ===== mobile top bar + nav strip (shown < 980px) ===== -->
<header class="lp-top">
  <a class="lp-brand" href="#top">
    <span class="lp-brand__mark"><?= icon('cross') ?></span>
    <span><span class="lp-brand__name">Lufwanyama</span></span>
  </a>
  <a class="b b-primary" href="<?= e(url('/register.php')) ?>"><?= icon('userplus') ?>Register</a>
</header>
<nav class="lp-strip" aria-label="Sections">
<?php foreach ($nav as [$href, $label, $ico]): ?>
  <a href="<?= e($href) ?>" data-spy="<?= e(ltrim($href, '#')) ?>"<?= $href === '#top' ? ' class="on"' : '' ?>><?= icon($ico) ?><?= e($label) ?></a>
<?php endforeach; ?>
</nav>

<div class="lp-main">
<main id="main" class="lp-wrap">

<?php if ($f): ?>
  <div class="msg <?= $f['type'] === 'ok' ? 'ok' : 'err' ?>" role="status" style="margin-bottom:22px"><?= icon($f['type'] === 'ok' ? 'check' : 'alert') ?><span><?= e($f['msg']) ?></span></div>
<?php endif; ?>

  <!-- ===== hero ===== -->
  <section class="hero2" aria-labelledby="hero-h">
    <div class="hero2__in">
      <span class="hero2__tag"><?= icon('shield') ?>Trusted care for your district</span>
      <h1 id="hero-h">Your health, <span>close to home</span></h1>
      <p>Book an appointment at your rural health centre and let a doctor review
         your case before you travel &mdash; no more lost days when the doctor is away.</p>
      <div class="hero2__cta">
        <a class="b b-primary" href="<?= e(url('/register.php')) ?>"><?= icon('calendar') ?>Book an appointment</a>
        <a class="b b-ghost" href="#services"><?= icon('arrow') ?>See our services</a>
      </div>
      <div class="hero2__stats">
        <div class="stat2"><span class="stat2__ic"><?= icon('building') ?></span><div><b>11</b><span>Health centres</span></div></div>
        <div class="stat2"><span class="stat2__ic"><?= icon('users') ?></span><div><b>133k+</b><span>Residents served</span></div></div>
        <div class="stat2"><span class="stat2__ic"><?= icon('clock') ?></span><div><b>24/7</b><span>Emergency line</span></div></div>
      </div>
    </div>
    <div class="hero2__art" aria-hidden="true">
      <div class="hero2__disc"><?= icon('cross') ?></div>
      <span class="hero2__chip c1"><?= icon('check') ?>Confirmed before you travel</span>
      <span class="hero2__chip c2"><?= icon('signal') ?>Works on a weak signal</span>
    </div>
  </section>

  <!-- ===== services ===== -->
  <section id="services" aria-labelledby="svc-h">
    <div class="sec-top">
      <span class="sec-eyebrow"><?= icon('pulse') ?>Our medical services</span>
      <h2 class="sec-h2" id="svc-h">Care for the whole family</h2>
      <p class="sec-lead">Request a visit with the right department at your nearest
         health centre. A doctor reviews every request before confirming a date.</p>
    </div>
    <div class="grid g-3 g-auto">
<?php foreach ($services as [$ico, $name, $desc]): ?>
      <article class="svc">
        <div class="svc__ic"><?= icon($ico) ?></div>
        <h3><?= e($name) ?></h3>
        <p><?= e($desc) ?></p>
      </article>
<?php endforeach; ?>
    </div>
  </section>

  <!-- ===== about ===== -->
  <section id="about" class="about" aria-labelledby="about-h">
    <div class="about__body">
      <span class="sec-eyebrow"><?= icon('shield') ?>About the service</span>
      <h2 class="sec-h2" id="about-h">Built for rural Lufwanyama</h2>
      <p>Lufwanyama District spreads 133,060 residents across 11,451 square
         kilometres, and specialist doctors visit only on certain days. This
         service lets you &mdash; or a health worker on your behalf &mdash; arrange
         care before making the journey.</p>
      <ul class="checks">
        <li><?= icon('check') ?>Book with a general or specialist doctor by availability</li>
        <li><?= icon('check') ?>A doctor reviews your clinical notes first</li>
        <li><?= icon('check') ?>Travel only once your visit is confirmed</li>
        <li><?= icon('check') ?>Keeps working through power and network cuts</li>
      </ul>
      <a class="b b-primary" href="<?= e(url('/register.php')) ?>"><?= icon('userplus') ?>Get started</a>
    </div>
    <div class="about__art" aria-hidden="true">
      <?= icon('building') ?>
      <div class="about__badge"><b>Paper&rarr;Digital</b><span>No more lost clinic folders</span></div>
    </div>
  </section>

  <!-- ===== doctors ===== -->
  <section id="doctors" aria-labelledby="doc-h">
    <div class="sec-row">
      <div>
        <span class="sec-eyebrow"><?= icon('users') ?>Meet our doctors</span>
        <h2 class="sec-h2" id="doc-h">Experienced, local clinicians</h2>
      </div>
      <a class="sec-link" href="<?= e(url('/register.php')) ?>">Book with a doctor <?= icon('arrow') ?></a>
    </div>
    <div class="grid g-auto">
<?php foreach ($doctors as [$name, $spec]): ?>
      <article class="doc">
        <div class="doc__pic" aria-hidden="true"><?= e(initials(preg_replace('/^Dr\.?\s+/i', '', $name))) ?></div>
        <h3><?= e($name) ?></h3>
        <p><?= e($spec) ?></p>
        <small>Available by appointment</small>
      </article>
<?php endforeach; ?>
    </div>
  </section>

  <!-- ===== highlight band ===== -->
  <section aria-label="How we help">
    <div class="band">
      <div class="band__c band--light">
        <div class="ic-lg"><?= icon('calendar') ?></div>
        <h3>How it works</h3>
        <p>Request a visit, a doctor reviews it, and you get a confirmed date, a new
           date, or advice &mdash; all before you leave home.</p>
        <a class="sec-link" href="#book">Learn more <?= icon('arrow') ?></a>
      </div>
      <div class="band__c band--teal">
        <div class="ic-lg"><?= icon('alert') ?></div>
        <h3>24/7 emergency care</h3>
        <p>For an emergency, call the district health line at any time, day or night.</p>
        <div class="phone"><?= icon('phone') ?><?= e($EMERGENCY) ?></div>
      </div>
      <div class="band__c band--navy">
        <div class="ic-lg"><?= icon('send') ?></div>
        <h3>Specialist review</h3>
        <p>When a specialist is away, your notes travel through the system instead of
           on a paper slip that can be lost.</p>
        <a class="sec-link" href="#about">Why it matters <?= icon('arrow') ?></a>
      </div>
    </div>
  </section>

  <!-- ===== book / appointment CTA ===== -->
  <section id="book" class="appt" aria-labelledby="book-h">
    <div class="appt__card">
      <span class="sec-eyebrow"><?= icon('calendar') ?>Book a visit</span>
      <h2 id="book-h">Four simple steps</h2>
      <p>No account is needed to look around, but you will sign in to book so your
         appointment stays private to you and your clinic.</p>
      <ol class="appt__steps">
        <li><div><b>Register or log in</b><span>Use your phone number &mdash; or ask a health worker to do it for you.</span></div></li>
        <li><div><b>Choose your centre and doctor</b><span>Pick a health centre, a department and an available date.</span></div></li>
        <li><div><b>A doctor reviews your request</b><span>They confirm, suggest another date, or reply with advice.</span></div></li>
        <li><div><b>Travel only when confirmed</b><span>Follow the status here and go once it reads Confirmed.</span></div></li>
      </ol>
      <a class="b b-primary" href="<?= e(url('/register.php')) ?>"><?= icon('userplus') ?>Create a free account</a>
    </div>
    <aside class="appt__aside">
      <h3>Already registered?</h3>
      <p>Log in to book a new appointment or follow one you have already requested.</p>
      <a class="b b-light b-wide" href="<?= e(url('/login.php')) ?>" style="margin-bottom:8px"><?= icon('login') ?>Log in to your account</a>
      <div class="appt__contact">
        <div class="row"><?= icon('phone') ?><div><small>Call us any time</small><b><?= e($EMERGENCY) ?></b></div></div>
        <div class="row"><?= icon('mail') ?><div><small>Email</small><b>info@lufwanyama.health</b></div></div>
        <div class="row"><?= icon('clock') ?><div><small>Centre hours</small><b>Mon&ndash;Fri, 08:00&ndash;17:00</b></div></div>
      </div>
    </aside>
  </section>

  <!-- ===== testimonials ===== -->
  <section id="reviews" aria-labelledby="rev-h">
    <div class="sec-top">
      <span class="sec-eyebrow"><?= icon('chat') ?>What people say</span>
      <h2 class="sec-h2" id="rev-h">Trusted across the district</h2>
    </div>
    <div class="grid g-3 g-auto">
<?php foreach ($reviews as [$text, $who, $role]): ?>
      <figure class="quote">
        <div class="quote__mark" aria-hidden="true">&ldquo;</div>
        <p><?= e($text) ?></p>
        <div class="stars" aria-label="5 out of 5">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
        <figcaption class="quote__who">
          <span class="quote__pic" aria-hidden="true"><?= e(initials($who)) ?></span>
          <span><b><?= e($who) ?></b><span><?= e($role) ?></span></span>
        </figcaption>
      </figure>
<?php endforeach; ?>
    </div>
  </section>

  <!-- ===== faq ===== -->
  <section id="faq" aria-labelledby="faq-h">
    <div class="sec-top">
      <span class="sec-eyebrow"><?= icon('clipboard') ?>Questions</span>
      <h2 class="sec-h2" id="faq-h">Frequently asked questions</h2>
    </div>
    <div class="faq">
<?php foreach ($faqs as $i => [$q, $a]): ?>
      <details<?= $i === 0 ? ' open' : '' ?>>
        <summary><span><?= e($q) ?></span><span class="pm" aria-hidden="true">+</span></summary>
        <div class="faq__a"><?= $a /* trusted static HTML (contains <strong>) */ ?></div>
      </details>
<?php endforeach; ?>
    </div>
  </section>

  <!-- ===== partners ===== -->
  <section class="partners" aria-label="In partnership with">
    <span class="sec-eyebrow"><?= icon('shield') ?>In partnership with</span>
    <div class="partners__row">
      <span><?= icon('building') ?>Ministry of Health</span>
      <span><?= icon('cross') ?>Lufwanyama District Health Office</span>
      <span><?= icon('shield') ?>Zambia Digital Health Strategy</span>
      <span><?= icon('pulse') ?>Rural Health Centres</span>
    </div>
  </section>

</main>

<!-- ===== footer ===== -->
<footer class="lp-foot" id="contact">
  <div class="lp-foot__top">
    <div class="lp-foot__brand">
      <a class="lp-brand" href="#top">
        <span class="lp-brand__mark"><?= icon('cross') ?></span>
        <span><span class="lp-brand__name">Lufwanyama</span><span class="lp-brand__sub">Telemedicine</span></span>
      </a>
      <p>Book an appointment at your rural health centre and consult a doctor before
         you travel. Built for low connectivity, so your request is never lost.</p>
    </div>
    <div>
      <h4>Services</h4>
      <ul>
        <li><a href="#services">General Medicine</a></li>
        <li><a href="#services">Paediatrics</a></li>
        <li><a href="#services">Maternity</a></li>
        <li><a href="#services">Mental Health</a></li>
      </ul>
    </div>
    <div>
      <h4>Quick links</h4>
      <ul>
        <li><a href="#about">About</a></li>
        <li><a href="#doctors">Our Doctors</a></li>
        <li><a href="#faq">FAQ</a></li>
        <li><a href="<?= e(url('/login.php')) ?>">Login</a></li>
        <li><a href="<?= e(url('/register.php')) ?>">Register</a></li>
      </ul>
    </div>
    <div class="lp-foot__contact">
      <h4>Contact</h4>
      <div class="row"><?= icon('mappin') ?><span>Lufwanyama District Health Office,<br>Copperbelt Province, Zambia</span></div>
      <div class="row"><?= icon('phone') ?><span><?= e($EMERGENCY) ?></span></div>
      <div class="row"><?= icon('mail') ?><span>info@lufwanyama.health</span></div>
    </div>
  </div>
  <div class="lp-foot__bar">
    <div>
      <span>&copy; <?= date('Y') ?> Lufwanyama District Telemedicine</span>
      <span>Built for rural connectivity &middot; your data stays private</span>
    </div>
  </div>
</footer>
</div><!-- /.lp-main -->

<div id="net" class="net" role="alert" hidden><?= e(t('offline')) ?></div>

<script>
/* Landing page only: highlight the sidebar link for the section in view.
   Progressive enhancement — anchor links work without it. */
(function () {
  if (!('IntersectionObserver' in window)) return;
  var links = {};
  document.querySelectorAll('[data-spy]').forEach(function (a) {
    (links[a.getAttribute('data-spy')] = links[a.getAttribute('data-spy')] || []).push(a);
  });
  var ids = ['services','about','doctors','book','reviews','faq','contact'];
  function setOn(id) {
    document.querySelectorAll('[data-spy]').forEach(function (a) { a.classList.remove('on'); });
    var top = (links[id] || links['top'] || []);
    top.forEach(function (a) { a.classList.add('on'); });
  }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (en.isIntersecting) setOn(en.target.id);
    });
  }, { rootMargin: '-45% 0px -50% 0px' });
  ids.forEach(function (id) { var el = document.getElementById(id); if (el) io.observe(el); });
})();
</script>
<script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</body>
</html>
