import org.jasypt.util.text.BasicTextEncryptor;
import java.util.Scanner;

public class JasyptEncryptorTool {
    public static void main(String[] args) {
        Scanner scanner = new Scanner(System.in);

        System.out.print("Digite a chave secreta (password): ");
        String secretKey = scanner.nextLine();

        System.out.print("Digite o texto a ser criptografado (ex: senha): ");
        String value = scanner.nextLine();

        BasicTextEncryptor encryptor = new BasicTextEncryptor();
        encryptor.setPassword(secretKey);

        String encrypted = encryptor.encrypt(value);
        System.out.println("\nValor criptografado:");
        System.out.println("ENC(" + encrypted + ")");
    }
}