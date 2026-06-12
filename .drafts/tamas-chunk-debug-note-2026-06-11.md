# Note to Tamas: RAG chunk visibility is live (ready to send)

Hi Tamas,

The debug feature you asked for last week is built and live on the dev sites as of v6.4.1.

What you get:

1. When prompt debug is on, the debug view for each turn now shows the exact RAG chunks the assistant retrieved for that question, each with its similarity score and source activity, alongside the prompt sections you already had. So you can see precisely which course content informed an answer and how strongly each piece matched.

2. The prompt playground now has a live retrieval test: type any question against an indexed course and it shows you which chunks come back and their scores, without having to run a real chat turn.

To try it: on any dev site, enable the prompt debug setting in the SOLA admin settings, ask the assistant a question in an indexed course (BUS101 works), then open the prompt debug view from the admin quicklinks. The chunk list is a new expandable section per turn.

Both of your asks from our meeting are now covered: the assistant uses five chunks per answer (that was already the default), and you can now see exactly which five and why.

Happy to walk you through it whenever suits.

Tom
