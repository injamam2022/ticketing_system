<?php $pageTitle = 'Client sign-in'; ?>
<?= $this->include('portal/header') ?>
<section class="portal-login-layout">
    <div class="portal-welcome"><span class="portal-eyebrow">LET’S KEEP YOUR PROJECT MOVING</span><h1>A direct line<br>to your team.</h1><p>Something not quite right? Have an idea to share?<br>Send us a ticket and follow every step toward a solution.</p><div class="welcome-steps"><span>01 <strong>Choose your project</strong></span><span>02 <strong>Tell us what you need</strong></span><span>03 <strong>Stay in the loop</strong></span></div></div>
    <form class="portal-panel portal-form login-panel" method="post" action="<?= site_url('portal/login') ?>">
        <span class="portal-eyebrow">YOUR CLIENT WORKSPACE</span><h2>Good to see you.</h2><p>Sign in with the account provided by your project team.</p>
        <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
        <label>Email address<input type="email" name="email" required autocomplete="username" placeholder="you@company.com"></label>
        <label>Password<input type="password" name="password" required autocomplete="current-password" placeholder="Your password"></label>
        <button class="button primary" type="submit">Sign in to client portal <span>→</span></button>
        <small>Need an account or a password reset?<br>Contact your project team.</small>
    </form>
</section>
<?= $this->include('portal/footer') ?>
