// Runs after the DOM is fully loaded
document.addEventListener('DOMContentLoaded', function() {
    const monitorButton = document.getElementById('monitor-scripts');
    const scriptList = document.getElementById('script-list');

    // Handles click event to fetch monitored scripts
    monitorButton.addEventListener('click', function() {
        fetchScripts();
    });

    // Fetches monitored scripts from the server via AJAX
    function fetchScripts() {
        fetch(ajaxurl + '?action=fetch_monitored_scripts')
            .then(response => response.json())
            .then(data => {
                displayScripts(data);
            })
            .catch(error => console.error('Error fetching scripts:', error));
    }

    // Displays the list of monitored scripts in the UI
    function displayScripts(scripts) {
        scriptList.innerHTML = '';
        scripts.forEach(script => {
            const listItem = document.createElement('li');
            listItem.textContent = script.name + ' - ' + script.type;
            scriptList.appendChild(listItem);
        });
    }
});