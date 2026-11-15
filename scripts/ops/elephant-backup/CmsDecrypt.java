import java.io.*;
import java.nio.file.*;
import java.security.*;
import org.bouncycastle.asn1.pkcs.PrivateKeyInfo;
import org.bouncycastle.cms.*;
import org.bouncycastle.cms.jcajce.JceKeyTransAuthEnvelopedRecipient;
import org.bouncycastle.jce.provider.BouncyCastleProvider;
import org.bouncycastle.openssl.PEMParser;
import org.bouncycastle.openssl.jcajce.JcaPEMKeyConverter;

/** Bounded-memory CMS AES-GCM recovery; publish plaintext only after MAC passes. */
public class CmsDecrypt {
    public static void main(String[] args) throws Exception {
        if (args.length != 3) throw new IllegalArgumentException("input.cms key.pem output.tar.gz");
        Security.addProvider(new BouncyCastleProvider());
        PrivateKey key;
        try (PEMParser pem = new PEMParser(Files.newBufferedReader(Path.of(args[1])))) {
            key = new JcaPEMKeyConverter().setProvider("BC")
                .getPrivateKey((PrivateKeyInfo)pem.readObject());
        }
        Path output = Path.of(args[2]);
        Path partial = output.resolveSibling(output.getFileName() + ".unverified");
        try {
            try (InputStream input = new BufferedInputStream(Files.newInputStream(Path.of(args[0])), 1024 * 1024);
                 OutputStream target = new BufferedOutputStream(Files.newOutputStream(partial), 1024 * 1024)) {
                CMSAuthEnvelopedDataParser parser = new CMSAuthEnvelopedDataParser(input);
                var recipients = parser.getRecipientInfos().getRecipients();
                if (recipients.size() != 1) throw new IOException("expected one recovery recipient");
                RecipientInformation recipient = recipients.iterator().next();
                try (InputStream plain = recipient.getContentStream(
                        new JceKeyTransAuthEnvelopedRecipient(key).setProvider("BC")).getContentStream()) {
                    plain.transferTo(target); // EOF validates the authentication tag.
                }
                parser.close();
            }
            Files.move(partial, output, StandardCopyOption.REPLACE_EXISTING);
        } finally {
            Files.deleteIfExists(partial);
        }
    }
}
