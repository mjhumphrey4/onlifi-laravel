pipeline {
    agent any
    stages {
        stage('Checkout') {
            steps {
                git branch: 'main', 
                url: 'https://github.com/mjhumphrey4/onlifi-laravel',
                credentialsId: 'onlifi-mikrotik'
            }
        }
        stage('Sync to Host Server') {
            steps {
                sh '''
                    pwd
                    ls -al
                    rsync -avz --exclude '.git' ./ hum@192.168.0.180:/var/www/onlifi
                '''
            }
        }

    }


    post {
        success {
            echo "Build succeeded, cleaning up synced files..."
            sh 'rm -rf ./files/*'
        }
    }
        
}
