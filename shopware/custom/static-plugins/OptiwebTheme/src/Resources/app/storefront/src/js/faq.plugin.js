import Plugin from 'src/plugin-system/plugin.class';

export default class OptiwebFaqPlugin extends Plugin {
    init() {
        this.questions = document.querySelectorAll(".ow-faq__single");

        this.questions.forEach((question) => {
            const questionElement = question.querySelector("h5");
            questionElement.addEventListener("click", (e) => {
                if (question.classList.contains("open")) {
                    question.classList.remove("open");
                } else {
                    question.parentNode.querySelectorAll(".ow-faq__single").forEach((question) => question.classList.remove("open"));
                    question.classList.add("open");
                }
            })
        })
    }
}
