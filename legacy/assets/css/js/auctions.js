document.addEventListener(
    "DOMContentLoaded",
    function () {

        const countdowns =
            document.querySelectorAll(
                ".auction-countdown"
            );


        function updateCountdown(
            countdown
        ) {

            const endTime =
                new Date(
                    countdown.dataset.endTime
                ).getTime();


            const now =
                new Date().getTime();


            let distance =
                endTime - now;


            const days =
                countdown.querySelector(
                    ".countdown-days"
                );

            const hours =
                countdown.querySelector(
                    ".countdown-hours"
                );

            const minutes =
                countdown.querySelector(
                    ".countdown-minutes"
                );

            const seconds =
                countdown.querySelector(
                    ".countdown-seconds"
                );


            if (distance <= 0) {

                countdown.innerHTML = `
                    <div class="auction-ended">
                        Auction Ended
                    </div>
                `;

                return;
            }


            const totalSeconds =
                Math.floor(
                    distance / 1000
                );


            const day =
                Math.floor(
                    totalSeconds /
                    86400
                );


            const hour =
                Math.floor(
                    (
                        totalSeconds %
                        86400
                    ) / 3600
                );


            const minute =
                Math.floor(
                    (
                        totalSeconds %
                        3600
                    ) / 60
                );


            const second =
                totalSeconds % 60;


            days.textContent =
                String(day)
                    .padStart(2, "0");


            hours.textContent =
                String(hour)
                    .padStart(2, "0");


            minutes.textContent =
                String(minute)
                    .padStart(2, "0");


            seconds.textContent =
                String(second)
                    .padStart(2, "0");

        }


        countdowns.forEach(
            function (countdown) {

                updateCountdown(
                    countdown
                );


                setInterval(
                    function () {

                        updateCountdown(
                            countdown
                        );

                    },
                    1000
                );

            }
        );

    }
);