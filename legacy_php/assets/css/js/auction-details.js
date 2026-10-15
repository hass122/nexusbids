document.addEventListener("DOMContentLoaded", function () {

    const countdown =
        document.querySelector(".auction-countdown");

    if (!countdown) {
        return;
    }

    const endTime =
        new Date(
            countdown.dataset.endTime
        ).getTime();


    function updateCountdown() {

        const now = new Date().getTime();

        const distance =
            endTime - now;


        if (distance <= 0) {

            countdown.innerHTML = `
                <div class="auction-ended">
                    Auction Ended
                </div>
            `;

            setTimeout(function () {
                location.reload();
            }, 3000);

            return;
        }


        const days =
            Math.floor(
                distance /
                (1000 * 60 * 60 * 24)
            );


        const hours =
            Math.floor(
                (
                    distance %
                    (1000 * 60 * 60 * 24)
                ) /
                (1000 * 60 * 60)
            );


        const minutes =
            Math.floor(
                (
                    distance %
                    (1000 * 60 * 60)
                ) /
                (1000 * 60)
            );


        const seconds =
            Math.floor(
                (
                    distance %
                    (1000 * 60)
                ) /
                1000
            );


        document.getElementById("days")
            .textContent =
            String(days).padStart(2, "0");


        document.getElementById("hours")
            .textContent =
            String(hours).padStart(2, "0");


        document.getElementById("minutes")
            .textContent =
            String(minutes).padStart(2, "0");


        document.getElementById("seconds")
            .textContent =
            String(seconds).padStart(2, "0");

    }


    updateCountdown();

    setInterval(
        updateCountdown,
        1000
    );

});