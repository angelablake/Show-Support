// Show Support plugin scripts

(function() {
    // Wait until DOM is ready just in case.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initShowSupport);
    } else {
        initShowSupport();
    }
    
    function initShowSupport() {
        const btn = document.querySelector('.show-support-btn');
        const countEl = document.querySelector('.show-support-count');
        const emoji = (window.ShowSupport && ShowSupport.emoji) || '❤️';

        // Bail if the button or localized data isn't available.
        if (!btn || !window.ShowSupport || !ShowSupport.restUrl || !ShowSupport.nonce) {
            return;
        }

        const STORAGE_KEY = 'show_support_last_click';
        const MIN_INTERVAL_MS = 250; // quarter second between counted clicks

        function getCurrentCount(countEl) {
        if (!countEl) return 0;
        const raw = countEl.textContent.replace(/[^\d]/g, '');
        const num = parseInt(raw || '0', 10);
        return isNaN(num) ? 0 : num;
    }

        function canSendClick() {
            try {
                const last = parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10);
                const now = Date.now();
                return (now - last) > MIN_INTERVAL_MS;
            } catch (e) {
                return true;
            }
        }

        function recordClickTime() {
            try {
                localStorage.setItem(STORAGE_KEY, String(Date.now()));
            } catch (e) {
                // Ignore if storage is unavailable.
            }
        }

        function launchConfetti() {
            if (!btn) return;
            
            const rect = btn.getBoundingClientRect();
            const centerX = rect.left + rect.width / 2;
            const centerY = rect.top + rect.height / 2;
            
            const numberOfEmojis = 22; // tweak as desired
            
            for (let i = 0; i < numberOfEmojis; i++) {
                const confetti = document.createElement('span');
                confetti.className = 'show-support-confetti';
                confetti.textContent = emoji;
                
                // Start at the button center (viewport coords)
                confetti.style.left = `${centerX}px`;
                confetti.style.top = `${centerY}px`;
                
                // Random burst direction
                const angle = Math.random() * Math.PI * 2; // 0 to 2π
                const distance = 100 + Math.random() * 300; // 60–180px
                
                const dx = Math.cos(angle) * distance;
                const dy = Math.sin(angle) * distance;
                
                // Pass vector into CSS via custom properties for the keyframe animation
                confetti.style.setProperty('--dx', `${dx}px`);
                confetti.style.setProperty('--dy', `${dy}px`);
                
                document.body.appendChild(confetti);
                
                // Remove after animation completes (matches 800ms in CSS, a bit extra padding)
                setTimeout(() => {
                    confetti.remove();
                }, 1000);
            }
        }

        let supportSound = null;
        function playSupportSound() {
            if (!ShowSupport.soundEnabled || !ShowSupport.soundUrl) {
                return;
            }
            try {
                if (!supportSound) {
                    supportSound = new Audio(ShowSupport.soundUrl);
                    supportSound.preload = 'auto';
                }
                supportSound.currentTime = 0;
                supportSound.play();
            } catch (e) {
                // Fail silently — sound is a nice-to-have
                }
            }

        async function sendClick() {
            if (!canSendClick(countEl)) {
                return;
            }

            recordClickTime();

            try {
                const response = await fetch(ShowSupport.restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': ShowSupport.nonce
                    },
                    body: JSON.stringify({})
                });

                // Optional: inspect returned total.
                // const data = await response.json();
                // console.log('Total Show Support clicks:', data.count);
            } catch (e) {
                // For this UX, silent failure is fine.
            }
        }

        btn.addEventListener('click', () => {
            launchConfetti();
            playSupportSound();
            
            // Optimistic UI update: bump the number right away.
            if (countEl) {
                const current = getCurrentCount(countEl);
                const next = current + 1;
                try {
                    countEl.textContent = next.toLocaleString();
                } catch (e) {
                    countEl.textContent = String(next);
                }
            }
            
            // Fire off the REST call in the background.
            sendClick(countEl);
        });
    }
})();