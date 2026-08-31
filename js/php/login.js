
    function switchTab(tab) {
        document.getElementById('panel-login').classList.toggle('active', tab === 'login');
        document.getElementById('panel-signup').classList.toggle('active', tab === 'signup');
        document.getElementById('panel-forgot').classList.toggle('active', tab === 'forgot');
        document.getElementById('tab-login').classList.toggle('active', tab === 'login');
        document.getElementById('tab-signup').classList.toggle('active', tab === 'signup');
    }

    function togglePwd(id, icon) {
        const inp = document.getElementById(id);
        inp.type = inp.type === 'password' ? 'text' : 'password';
        icon.classList.toggle('bx-hide');
        icon.classList.toggle('bx-show');
    }

    function showAlert(msg, isError = true, alertId = 'signup-alert') {
        const box = document.getElementById(alertId);
        box.style.display = 'flex';
        box.className = `alert ${isError ? 'alert-error' : 'alert-success'}`;
        box.innerHTML = `<i class='bx ${isError ? 'bx-error-circle' : 'bx-check-circle'}'></i><span>${msg}</span>`;
    }

    /* ── SIGNUP: OTP REQUEST ── */
    function handleOTPRequest() {
        const fname   = document.getElementById('first_name').value.trim();
        const lname   = document.getElementById('last_name').value.trim();
        const uname   = document.getElementById('signup_username').value.trim();
        const email   = document.getElementById('signup_email').value.trim();
        const phone   = document.getElementById('phone_number').value.trim();
        const pass    = document.getElementById('signup_password').value;
        const confirm = document.getElementById('signup_password_confirm').value;
        const btn     = document.getElementById('btn-otp');

        if (!fname || !lname || !uname || !email || !phone || !pass) { showAlert("Please complete all fields.", true, 'signup-alert'); return; }
        if (pass !== confirm) { showAlert("Passwords do not match.", true, 'signup-alert'); return; }
        if (pass.length < 8) { showAlert("Password must be at least 8 characters long.", true, 'signup-alert'); return; }

        btn.disabled = true;
        btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Sending Code…";

        const fd = new FormData();
        fd.append('action', 'request_otp');
        fd.append('first_name', fname);
        fd.append('last_name', lname);
        fd.append('username', uname);
        fd.append('email', email);
        fd.append('phone_number', phone);
        fd.append('password', pass);

        fetch('register_action.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-send'></i> Request Clearance Code";
            if (data.trim() === 'otp_sent') {
                document.getElementById('signup-step-1').style.display = 'none';
                document.getElementById('signup-step-2').style.display = 'block';
                document.getElementById('step2-dot').classList.add('active');
                document.getElementById('step-label').textContent = '2';
                showAlert("Verification code sent! Please check your inbox.", false, 'signup-alert');
            } else {
                showAlert(data.trim(), true, 'signup-alert');
            }
        }).catch(() => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-send'></i> Request Clearance Code";
            showAlert("Network error. Please try again.", true, 'signup-alert');
        });
    }

    /* ── SIGNUP: OTP VERIFY ── */
    function handleOTPVerify() {
        const otp = document.getElementById('otp_code').value.trim();
        const btn = document.getElementById('btn-verify');
        if (!otp || otp.length < 6) { showAlert("Enter the full 6-character code.", true, 'signup-alert'); return; }

        btn.disabled = true;
        btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Authenticating…";

        const fd = new FormData();
        fd.append('action', 'verify_otp');
        fd.append('otp', otp);

        fetch('register_action.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-check-shield'></i> Verify & Submit Account";
            if (data.trim() === 'account_created') {
                showAlert("Account successfully submitted! Redirecting...", false, 'signup-alert');
                setTimeout(() => { location.href = "login.php?signup=success"; }, 1400);
            } else {
                showAlert(data.trim(), true, 'signup-alert');
            }
        }).catch(() => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-check-shield'></i> Verify & Submit Account";
            showAlert("Network error. Please try again.", true, 'signup-alert');
        });
    }

    /* ── FORGOT PASSWORD: REQUEST OTP ── */
    function handleResetOTPRequest() {
        const identity = document.getElementById('forgot_identity').value.trim();
        const btn      = document.getElementById('btn-forgot-otp');
        if (!identity) { showAlert("Please enter your username or email.", true, 'forgot-alert'); return; }

        btn.disabled = true;
        btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Sending Code…";

        const fd = new FormData();
        fd.append('action', 'request_reset_otp');
        fd.append('identity', identity);

        fetch('forgot_password_action.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-send'></i> Send Recovery Code";
            const clean = data.trim();
            if (clean === 'otp_sent') {
                document.getElementById('forgot-step-1').style.display = 'none';
                document.getElementById('forgot-step-2').style.display = 'block';
                showAlert("Recovery code sent! Please check your email inbox.", false, 'forgot-alert');
            } else if (clean === 'user_not_found') {
                showAlert("Account could not be found.", true, 'forgot-alert');
            } else {
                showAlert(clean, true, 'forgot-alert');
            }
        }).catch(() => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-send'></i> Send Recovery Code";
            showAlert("Network error. Please try again.", true, 'forgot-alert');
        });
    }

    /* ── FORGOT PASSWORD: RESET ACTION ── */
    function handleResetPassword() {
        const otp         = document.getElementById('forgot_otp_code').value.trim();
        const newPass     = document.getElementById('forgot_new_password').value;
        const confirmPass = document.getElementById('forgot_confirm_password').value;
        const btn         = document.getElementById('btn-reset-pass');

        if (!otp || otp.length < 6) { showAlert("Enter the 6-digit recovery code.", true, 'forgot-alert'); return; }
        if (!newPass || newPass.length < 8) { showAlert("Password must be at least 8 characters.", true, 'forgot-alert'); return; }
        if (newPass !== confirmPass) { showAlert("Passwords do not match.", true, 'forgot-alert'); return; }

        btn.disabled = true;
        btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Updating Password…";

        const fd = new FormData();
        fd.append('action', 'verify_reset_password');
        fd.append('otp', otp);
        fd.append('new_password', newPass);

        fetch('forgot_password_action.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-key'></i> Update Password";
            const clean = data.trim();
            if (clean === 'password_updated') {
                showAlert("Password updated! Redirecting to sign in...", false, 'forgot-alert');
                setTimeout(() => { location.href = "login.php?reset=success"; }, 1400);
            } else if (clean === 'invalid_otp') {
                showAlert("Invalid or expired recovery code.", true, 'forgot-alert');
            } else {
                showAlert(clean, true, 'forgot-alert');
            }
        }).catch(() => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-key'></i> Update Password";
            showAlert("Network error. Please try again.", true, 'forgot-alert');
        });
    }