document.addEventListener("DOMContentLoaded", function () {
    const countdown = document.querySelector(".auction-countdown");

    // Exit early if element or end-time attribute is missing
    if (!countdown || !countdown.dataset.endTime) {
        return;
    }

    const endTime = new Date(countdown.dataset.endTime).getTime();

    // Prevent execution if date parsing fails
    if (isNaN(endTime)) {
        console.error("NexusBids Timer Error: Invalid date format in data-end-time.");
        return;
    }

    let timerInterval = null;

    function updateCountdown() {
        const now = new Date().getTime();
        const distance = endTime - now;

        // When the auction time expires
        if (distance <= 0) {
            // Stop the interval loop to avoid targeting destroyed DOM elements
            if (timerInterval) {
                clearInterval(timerInterval);
            }

            // Display auction ended message inside countdown container
            countdown.innerHTML = `
                <div class="auction-ended">
                    <i class="fa-solid fa-circle-xmark"></i>
                    <span>Auction Ended</span>
                </div>
            `;

            // Reload page after 3 seconds to fetch final winner/ended status from server
            setTimeout(function () {
                location.reload();
            }, 3000);

            return;
        }

        // Calculate remaining time units
        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
        const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);

        // Fetch DOM nodes dynamically
        const daysEl = document.getElementById("days");
        const hoursEl = document.getElementById("hours");
        const minutesEl = document.getElementById("minutes");
        const secondsEl = document.getElementById("seconds");

        // Safely update DOM text values with leading zeros
        if (daysEl) daysEl.textContent = String(days).padStart(2, "0");
        if (hoursEl) hoursEl.textContent = String(hours).padStart(2, "0");
        if (minutesEl) minutesEl.textContent = String(minutes).padStart(2, "0");
        if (secondsEl) secondsEl.textContent = String(seconds).padStart(2, "0");
    }

    // Run initial execution immediately on load
    updateCountdown();

    // Store interval reference and run every second
    timerInterval = setInterval(updateCountdown, 1000);
});