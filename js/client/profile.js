
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
            let currentSize = parseInt(html.style.fontSize) || 16;

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
            fetch('../admin/admin_actions.php', { method: 'POST', body: fd }).catch(err => console.error(err));
        }

        function updateThemeButtonUI() {
            const isDark = document.documentElement.classList.contains('global-dark-mode');
            let btn = document.getElementById('btn-theme');
            if(btn) {
                if(isDark) {
                    btn.innerHTML = "<i class='bx bx-sun'></i> ENABLE LIGHT MODE";
                    btn.style.background = "#f4f6f9"; btn.style.color = "#111";
                } else {
                    btn.innerHTML = "<i class='bx bx-moon'></i> ENABLE DARK MODE";
                    btn.style.background = "#222"; btn.style.color = "white";
                }
            }
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('global-dark-mode');
            const savedFont = localStorage.getItem('fontSize');
            if (savedFont) document.documentElement.style.fontSize = savedFont;
            updateThemeButtonUI();
        });

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
            let saveBtn = document.getElementById('btn-save');
            
            let currentPwd = document.getElementById('current_password').value;
            let newPwd = document.getElementById('new_password').value;
            let confirmPwd = document.getElementById('confirm_password').value;

            alertBox.style.display = 'none';

            // 🚀 THE FIX: Force the user to enter their current password, and cleanly validate new passwords!
            if (currentPwd === "") {
                alertBox.className = 'alert-box alert-error';
                alertBox.textContent = "Current password is required to save changes.";
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
            fd.append('phone_number', document.getElementById('phone_number').value);
            fd.append('position', document.getElementById('position').value);
            fd.append('barangay', document.getElementById('barangay').value);
            
            fd.append('current_password', currentPwd);
            fd.append('new_password', newPwd);
            
            let pic = document.getElementById('profileInput').files[0];
            if (pic) fd.append('profile_picture', pic);

            saveBtn.innerHTML = "<i class='bx bx-loader bx-spin'></i> SAVING...";
            saveBtn.disabled = true;

            fetch('../admin/admin_actions.php', { method: 'POST', body: fd })
            .then(async res => {
                const rawText = await res.text();
                try {
                    return JSON.parse(rawText);
                } catch (e) {
                    throw new Error(rawText);
                }
            })
            .then(data => {
                alertBox.className = data.success ? 'alert-box alert-success' : 'alert-box alert-error';
                alertBox.textContent = data.message;
                alertBox.style.display = 'block';
                alertBox.style.opacity = '1';

                if (data.success) {
                    setTimeout(() => { location.reload(); }, 1500);
                } else {
                    saveBtn.innerHTML = "<i class='bx bxs-edit'></i> SAVE CHANGES";
                    saveBtn.disabled = false;
                }
            })
            .catch(err => {
                alertBox.className = 'alert-box alert-error';
                alertBox.innerHTML = "<b>ERROR:</b> " + err.message;
                alertBox.style.display = 'block';
                saveBtn.innerHTML = "<i class='bx bxs-edit'></i> SAVE CHANGES"; 
                saveBtn.disabled = false;
            });
        }