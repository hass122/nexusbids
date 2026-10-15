/* =========================================================
   NEXUSBIDS
   HOMEPAGE JAVASCRIPT
========================================================= */

document.addEventListener("DOMContentLoaded", () => {

    /* =====================================================
       MOBILE NAVIGATION
    ===================================================== */

    const menuToggle = document.getElementById("menuToggle");
    const navMenu = document.getElementById("navMenu");

    if (menuToggle && navMenu) {

        menuToggle.addEventListener("click", () => {

            navMenu.classList.toggle("show");

            const icon = menuToggle.querySelector("i");

            if (navMenu.classList.contains("show")) {
                icon.classList.remove("fa-bars");
                icon.classList.add("fa-xmark");
            } else {
                icon.classList.remove("fa-xmark");
                icon.classList.add("fa-bars");
            }

        });

    }
    


    /* =====================================================
       CLOSE MOBILE MENU WHEN LINK IS CLICKED
    ===================================================== */

    document.querySelectorAll(".nav-link").forEach(link => {

        link.addEventListener("click", () => {

            navMenu.classList.remove("show");

            const icon = menuToggle?.querySelector("i");

            if (icon) {
                icon.classList.remove("fa-xmark");
                icon.classList.add("fa-bars");
            }

        });

    });


    /* =====================================================
       ACTIVE NAVIGATION
    ===================================================== */

    const navLinks = document.querySelectorAll(".nav-link");

    navLinks.forEach(link => {

        link.addEventListener("click", () => {

            navLinks.forEach(item => {
                item.classList.remove("active");
            });

            link.classList.add("active");

        });

    });


    /* =====================================================
       TOAST SYSTEM
    ===================================================== */

    const toast = document.getElementById("toast");
    const toastTitle = document.getElementById("toastTitle");
    const toastMessage = document.getElementById("toastMessage");
    const toastClose = document.getElementById("toastClose");

    let toastTimer;


    function showToast(title, message) {

        if (!toast) return;

        toastTitle.textContent = title;
        toastMessage.textContent = message;

        toast.classList.add("show");

        clearTimeout(toastTimer);

        toastTimer = setTimeout(() => {
            toast.classList.remove("show");
        }, 3500);

    }


    if (toastClose) {

        toastClose.addEventListener("click", () => {
            toast.classList.remove("show");
        });

    }


    /* =====================================================
       FAVORITE / WATCHLIST
    ===================================================== */

    const favoriteButtons = document.querySelectorAll(
        ".favorite-btn, .watch-btn"
    );

    favoriteButtons.forEach(button => {

        button.addEventListener("click", event => {

            event.preventDefault();

            button.classList.toggle("active");

            const icon = button.querySelector("i");

            if (button.classList.contains("active")) {

                icon.classList.remove("fa-regular");
                icon.classList.add("fa-solid");

                showToast(
                    "Added to Watchlist",
                    "This auction has been added to your watchlist."
                );

            } else {

                icon.classList.remove("fa-solid");
                icon.classList.add("fa-regular");

                showToast(
                    "Removed",
                    "Auction removed from your watchlist."
                );

            }

        });

    });


    /* =====================================================
       BID BUTTONS
    ===================================================== */

    const bidButtons = document.querySelectorAll(
        ".bid-button, .card-bid-btn"
    );

    bidButtons.forEach(button => {

        button.addEventListener("click", () => {

            showToast(
                "Login Required",
                "Please login or register to place a bid."
            );

        });

    });


    /* =====================================================
       HERO COUNTDOWN
    ===================================================== */

   const heroCountdown = document.querySelector(".hero-countdown");

    if (heroCountdown && heroCountdown.dataset.endTime) {

        const heroEndTime = new Date(heroCountdown.dataset.endTime).getTime();

        const heroHoursEl = document.getElementById("hero-hours");
        const heroMinutesEl = document.getElementById("hero-minutes");
        const heroSecondsEl = document.getElementById("hero-seconds");

        let heroInterval;

        function updateHeroCountdown() {

            const distance = heroEndTime - Date.now();

            if (distance <= 0) {

                if (heroHoursEl) heroHoursEl.textContent = "00";
                if (heroMinutesEl) heroMinutesEl.textContent = "00";
                if (heroSecondsEl) heroSecondsEl.textContent = "00";

                clearInterval(heroInterval);
                return;
            }

            const hours = Math.floor(distance / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            if (heroHoursEl) heroHoursEl.textContent = String(hours).padStart(2, "0");
            if (heroMinutesEl) heroMinutesEl.textContent = String(minutes).padStart(2, "0");
            if (heroSecondsEl) heroSecondsEl.textContent = String(seconds).padStart(2, "0");

        }

        updateHeroCountdown();
        heroInterval = setInterval(updateHeroCountdown, 1000);

    }


    /* =====================================================
       AUCTION CARD COUNTDOWNS
    ===================================================== */

    const countdowns = document.querySelectorAll(".mini-countdown");

    countdowns.forEach(timer => {

        if (!timer.dataset.endTime) {
            return;
        }

        const endTime = new Date(timer.dataset.endTime).getTime();
        const output = timer.querySelector(".countdown-value");

        let cardInterval;

        function updateCountdown() {

            const distance = endTime - Date.now();

            if (distance <= 0) {

                timer.innerHTML =
                    `<i class="fa-solid fa-circle-check"></i>
                     <span class="countdown-value">Auction Ended</span>`;

                clearInterval(cardInterval);
                return;
            }

            const hours = Math.floor(distance / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            const formatted =
                `${String(hours).padStart(2, "0")}h ` +
                `${String(minutes).padStart(2, "0")}m ` +
                `${String(seconds).padStart(2, "0")}s`;

            if (output) {
                output.textContent = formatted;
            }

        }

        updateCountdown();
        cardInterval = setInterval(updateCountdown, 1000);

    });


    /* =====================================================
       SEARCH & FILTER
    ===================================================== */

    const searchInput =
        document.getElementById("auctionSearch");

    const categorySelect =
        document.getElementById("categorySelect");

    const searchButton =
        document.getElementById("searchButton");

    const auctionGrid =
        document.getElementById("auctionGrid");


    function filterAuctions() {

        if (!auctionGrid) return;

        const searchTerm =
            searchInput.value.toLowerCase().trim();

        const selectedCategory =
            categorySelect.value.toLowerCase();

        const cards =
            auctionGrid.querySelectorAll(".auction-card");

        let visibleCards = 0;


        cards.forEach(card => {

            const name =
                card.dataset.name.toLowerCase();

            const category =
                card.dataset.category.toLowerCase();


            const matchesSearch =
                name.includes(searchTerm) ||
                category.includes(searchTerm);

            const matchesCategory =
                selectedCategory === "all" ||
                category === selectedCategory;


            if (matchesSearch && matchesCategory) {

                card.style.display = "";

                visibleCards++;

            } else {

                card.style.display = "none";

            }

        });


        const oldMessage =
            auctionGrid.querySelector(".no-results");

        if (oldMessage) {
            oldMessage.remove();
        }


        if (visibleCards === 0) {

            const emptyState =
                document.createElement("div");

            emptyState.className = "no-results";

            emptyState.innerHTML = `
                <i class="fa-solid fa-magnifying-glass"></i>
                <h3>No Auctions Found</h3>
                <p>
                    Try another search term or category.
                </p>
            `;

            auctionGrid.appendChild(emptyState);

        }

    }


    if (searchButton) {

        searchButton.addEventListener("click", filterAuctions);

    }


    if (searchInput) {

        searchInput.addEventListener("input", filterAuctions);

    }


    if (categorySelect) {

        categorySelect.addEventListener(
            "change",
            filterAuctions
        );

    }


    /* =====================================================
       COUNTER ANIMATION
    ===================================================== */

    const counters =
        document.querySelectorAll(".counter");

    let counterStarted = false;


    function animateCounters() {

        if (counterStarted) return;

        const statsSection =
            document.querySelector(".stats-section");

        if (!statsSection) return;


        const rect =
            statsSection.getBoundingClientRect();


        if (rect.top < window.innerHeight * 0.85) {

            counterStarted = true;


            counters.forEach(counter => {

                const target =
                    parseInt(counter.dataset.target);

                let current = 0;

                const increment =
                    Math.max(1, Math.ceil(target / 80));


                function updateCounter() {

                    current += increment;

                    if (current >= target) {

                        counter.textContent =
                            target.toLocaleString();

                        return;

                    }

                    counter.textContent =
                        current.toLocaleString();

                    requestAnimationFrame(updateCounter);

                }


                updateCounter();

            });

        }

    }


    window.addEventListener(
        "scroll",
        animateCounters
    );

    animateCounters();


    /* =====================================================
       NEWSLETTER
    ===================================================== */

    const newsletterForm =
        document.getElementById("newsletterForm");


    if (newsletterForm) {

        newsletterForm.addEventListener("submit", event => {

            event.preventDefault();


            const email =
                document.getElementById("newsletterEmail").value;


            if (!email) {

                showToast(
                    "Email Required",
                    "Please enter your email address."
                );

                return;

            }


            showToast(
                "Subscribed!",
                "You will now receive NexusBids auction updates."
            );


            newsletterForm.reset();

        });

    }


    /* =====================================================
       SMOOTH SCROLL
    ===================================================== */

    document.querySelectorAll('a[href^="#"]').forEach(link => {

        link.addEventListener("click", event => {

            const targetId =
                link.getAttribute("href");

            if (
                targetId &&
                targetId !== "#" &&
                document.querySelector(targetId)
            ) {

                event.preventDefault();

                document
                    .querySelector(targetId)
                    .scrollIntoView({
                        behavior: "smooth",
                        block: "start"
                    });

            }

        });

    });


    /* =====================================================
       SCROLL REVEAL
    ===================================================== */

    const revealElements = document.querySelectorAll(
        ".auction-card, .category-card, .step, .feature-item"
    );


    const revealObserver =
        new IntersectionObserver(
            entries => {

                entries.forEach(entry => {

                    if (entry.isIntersecting) {

                        entry.target.style.opacity = "1";
                        entry.target.style.transform =
                            "translateY(0)";

                        revealObserver.unobserve(
                            entry.target
                        );

                    }

                });

            },
            {
                threshold: 0.1
            }
        );


    revealElements.forEach(element => {

        element.style.opacity = "0";
        element.style.transform =
            "translateY(20px)";
        element.style.transition =
            "opacity 0.6s ease, transform 0.6s ease";

        revealObserver.observe(element);

    });


});