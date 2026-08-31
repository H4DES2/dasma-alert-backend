        function toggleTheme() {
            const html = document.documentElement;
            html.classList.toggle('global-dark-mode');
            const isDark = html.classList.contains('global-dark-mode');
            const newTheme = isDark ? 'dark' : 'light';
            const currentFont = html.style.fontSize || '16px';
            updateThemeButtonUI();
            savePreferencesToDB(newTheme, currentFont);
        }

        function changeFont(action) {
            const html = document.documentElement;
            let compSize = window.getComputedStyle(html).fontSize;
            let currentSize = parseInt(html.style.fontSize) || parseInt(compSize) || 16;
            
            if (action === 'increase' && currentSize < 24) currentSize += 2;
            else if (action === 'decrease' && currentSize > 12) currentSize -= 2;
            else if (action === 'reset') currentSize = 16;
            
            const newSize = currentSize + 'px';
            const currentTheme = html.classList.contains('global-dark-mode') ? 'dark' : 'light';
            
            html.style.fontSize = newSize;
            savePreferencesToDB(currentTheme, newSize);
        }

        function savePreferencesToDB(theme, fontSize) {
            let fd = new FormData();
            fd.append('action', 'save_preferences');
            fd.append('theme', theme);
            fd.append('font_size', fontSize);
            fetch('admin_actions.php', { method: 'POST', body: fd }).catch(err => console.error('Error saving preferences:', err));
        }

        function updateThemeButtonUI() {
            const isDark = document.documentElement.classList.contains('global-dark-mode');
            let btn = document.getElementById('btn-theme');
            if(btn) {
                btn.innerHTML = isDark ? "<i class='bx bx-sun'></i> ENABLE LIGHT MODE" : "<i class='bx bx-moon'></i> ENABLE DARK MODE";
            }
        }
        
        document.addEventListener('DOMContentLoaded', updateThemeButtonUI);

        function togglePwd(inputId, icon) {
            let input = document.getElementById(inputId);
            input.type = input.type === "password" ? "text" : "password";
            icon.classList.toggle('bx-show'); icon.classList.toggle('bx-hide');
        }

        function previewImage(input) {
            if (input.files && input.files[0]) {
                let reader = new FileReader();
                reader.onload = e => document.getElementById('photoPreview').innerHTML = `<img src="${e.target.result}" style="width: 100%; height: 100%; object-fit: cover;">`;
                reader.readAsDataURL(input.files[0]);
            }
        }

        function updateAccount() {
            const alertBox = document.getElementById('profile-alert-box');
            let currentPwd = document.getElementById('current_password').value.trim();
            let newPwd = document.getElementById('new_password').value;
            let confirmPwd = document.getElementById('confirm_password').value;

            if (currentPwd === "") {
                alertBox.className = 'alert-box alert-error';
                alertBox.textContent = "Please enter your Current Password to save changes.";
                alertBox.style.display = 'block';
                return;
            }

            if (newPwd !== "" && newPwd !== confirmPwd) {
                alertBox.className = 'alert-box alert-error';
                alertBox.textContent = "New passwords do not match!";
                alertBox.style.display = 'block';
                return;
            }

            let fd = new FormData();
            fd.append('action', 'update_admin_account');
            fd.append('first_name', document.getElementById('first_name').value);
            fd.append('last_name', document.getElementById('last_name').value);
            fd.append('username', document.getElementById('username').value);
            fd.append('email', document.getElementById('email').value);
            fd.append('phone_number', document.getElementById('phone_number').value);
            fd.append('department', document.getElementById('department').value);
            fd.append('radio_callsign', document.getElementById('radio_callsign').value);
            fd.append('position', document.getElementById('position').value);
            fd.append('current_password', currentPwd);
            fd.append('new_password', newPwd);
            
            let pic = document.getElementById('profileInput').files[0];
            if (pic) fd.append('profile_picture', pic);

            let saveBtn = document.querySelector('.btn-save');
            saveBtn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> SYNCHRONIZING...";
            saveBtn.disabled = true;

            fetch('admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.text())
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch(e) {
                    console.error("Server raw response:", text);
                    alertBox.className = 'alert-box alert-error';
                    alertBox.textContent = "Server error occurred. Check browser console.";
                    alertBox.style.display = 'block';
                    saveBtn.innerHTML = "<i class='bx bxs-check-shield'></i> COMMIT PROFILE UPDATES";
                    saveBtn.disabled = false;
                    return;
                }

                alertBox.className = data.success ? 'alert-box alert-success' : 'alert-box alert-error';
                alertBox.textContent = data.message;
                alertBox.style.display = 'block';

                if (data.success) {
                    setTimeout(() => { location.reload(); }, 1200);
                } else {
                    saveBtn.innerHTML = "<i class='bx bxs-check-shield'></i> COMMIT PROFILE UPDATES";
                    saveBtn.disabled = false;
                }
            })
            .catch(err => {
                console.error("Fetch Error:", err);
                alertBox.className = 'alert-box alert-error';
                alertBox.textContent = "Network error. Please try again.";
                alertBox.style.display = 'block';
                saveBtn.innerHTML = "<i class='bx bxs-check-shield'></i> COMMIT PROFILE UPDATES"; 
                saveBtn.disabled = false;
            });
        }