package tests;

import java.time.Duration;
import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.Order;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.TestMethodOrder;
import org.junit.jupiter.api.MethodOrderer.OrderAnnotation;
import org.openqa.selenium.By;
import org.openqa.selenium.WebDriver;
import org.openqa.selenium.WebElement;
import org.openqa.selenium.NoSuchElementException;
import org.openqa.selenium.interactions.Actions;
import org.openqa.selenium.JavascriptExecutor;
import org.openqa.selenium.support.ui.ExpectedConditions;
import org.openqa.selenium.support.ui.WebDriverWait;

@TestMethodOrder(OrderAnnotation.class)
public class DSG extends BaseTest {

    @Test
    @Order(1)
    public void addNew() {
        try {
            authenticate();
            Thread.sleep(2000);

            WebDriverWait wait = new WebDriverWait(driver, Duration.ofSeconds(10));
            Actions actions = new Actions(driver);
            WebElement dropdownMenu = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//a[@title='Manage Study Elements' and contains(text(), 'Study Elements')]")));
            actions.moveToElement(dropdownMenu).click().perform();
            Thread.sleep(1000);

            WebElement subOption = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//a[@title='Manage DSG Templates' and text()='Manage DSG Templates']")));
            subOption.click();

            wait.until(ExpectedConditions.urlContains("/rep/select/mt/dsg/table/1/9/none"));
            WebElement addButton = wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-add-element")));
            addButton.click();

            wait.until(ExpectedConditions.urlContains("/rep/manage/addmt/dsg/none/F"));
            WebElement nameField = driver.findElement(By.id("edit-mt-name"));
            nameField.sendKeys(System.getProperty("card-name"));
            WebElement abbreviationField = driver.findElement(By.id("edit-mt-version"));
            abbreviationField.sendKeys("1");

            String filePath = System.getProperty("user.dir") + System.getProperty("file");
            WebElement uploadField = driver.findElement(By.id("edit-mt-filename-upload"));
            uploadField.sendKeys(filePath);
            Thread.sleep(5000);

            WebElement saveButton = driver.findElement(By.id("edit-save-submit"));
            ((JavascriptExecutor) driver).executeScript("arguments[0].scrollIntoView(true);", saveButton);
            saveButton.click();

            wait.until(ExpectedConditions.urlContains("/rep/select/mt/dsg/table/1/9/none"));
            WebElement dsgTable = driver.findElement(By.id("edit-element-table"));
            WebElement addedDSGTemplate = dsgTable.findElement(By.xpath("//td[contains(text(), '" + System.getProperty("card-name") + "')]"));

            Assertions.assertNotNull(addedDSGTemplate, "DSG Template File not found on List");
        } catch (Exception e) {
            Assertions.fail("Add New DSG Template failed: \n\n" + e.getMessage());
        }
    }

    @Test
    @Order(2)
    public void ingestDSG() {
        try {
            authenticate();
            Thread.sleep(2000);

            WebDriverWait wait = new WebDriverWait(driver, Duration.ofSeconds(10));
            Actions actions = new Actions(driver);
            WebElement dropdownMenu = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//a[@title='Manage Study Elements' and contains(text(), 'Study Elements')]")));
            actions.moveToElement(dropdownMenu).click().perform();
            Thread.sleep(1000);

            WebElement subOption = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//a[@title='Manage DSG Templates' and text()='Manage DSG Templates']")));
            subOption.click();

            // Muda para vista de Card
            wait.until(ExpectedConditions.urlContains("/rep/select/mt/dsg/table/1/9/none"));
            WebElement addButton = wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-card-view")));
            addButton.click();

            // Verifica se existe um card com o título "NHANES 2017-2018"
            WebElement dsgCard = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//h5[contains(text(), '" + System.getProperty("card-name") + "')]/ancestor::div[contains(@class, 'card')]")));

            // Clica no botão "Ingest" dentro do card específico
            WebElement ingestButton = dsgCard.findElement(By.xpath(".//button[@data-drupal-selector='edit-ingest']"));
            ((JavascriptExecutor) driver).executeScript("arguments[0].scrollIntoView(true);", ingestButton);
            ingestButton.click();
            Thread.sleep(2000);

            // Loop de verificação de Status: PROCESSED dentro do card
            boolean isProcessed = false;
            for (int i = 0; i < 10; i++) {
                // Atualiza a página
                driver.navigate().refresh();

                // Reencontra o card após o refresh
                dsgCard = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//h5[contains(text(), '" + System.getProperty("card-name") + "')]/ancestor::div[contains(@class, 'card')]")));

                try {
                    // Localiza o campo de status no card atualizado e obtém o texto de dentro do elemento <font>
                    WebElement statusElement = dsgCard.findElement(By.xpath(".//*[@data-drupal-selector='edit-element-status']//font"));
                    String statusText = statusElement.getText().trim();

                    // Verifica se o status é exatamente "PROCESSED"
                    if (statusText.equals("PROCESSED")) {
                        isProcessed = true;
                        break;
                    }
                } catch (NoSuchElementException e) {
                    isProcessed = false;
                }

                // Espera antes da próxima tentativa
                Thread.sleep(7000);
            }

            // Verificação final para garantir que o status é "PROCESSED"
            Assertions.assertTrue(isProcessed, "Status did not reach 'PROCESSED' after 5 attempts");

        } catch (Exception e) {
            Assertions.fail("Ingest DSG Template failed: \n\n" + e.getMessage());
        }
    }

    @Test
    @Order(3)
    public void studyExists() {
        try {
            authenticate();
            Thread.sleep(2000);
    
            WebDriverWait wait = new WebDriverWait(driver, Duration.ofSeconds(10));
            Actions actions = new Actions(driver);
            WebElement dropdownMenu = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//a[@title='Manage Study Elements' and contains(text(), 'Study Elements')]")));
            actions.moveToElement(dropdownMenu).click().perform();
            Thread.sleep(1000);
    
            WebElement subOption = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//a[@title='Manage Studies' and text()='Manage Studies']")));
            subOption.click();
    
            // Verifica se existe um card com o título "NHANES 2017-2018"
            wait.until(ExpectedConditions.urlContains("/std/select/study/1/9"));
            WebElement dsgCard = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//h5[contains(text(), '" + System.getProperty("dsg-title") + "')]/ancestor::div[contains(@class, 'card-header')]")));
    
            // Verificação final para garantir que o card foi encontrado
            Assertions.assertNotNull(dsgCard, "Study not found");
    
        } catch (Exception e) {
            Assertions.fail("Study 'NHANES 2017-2018' not found: \n\n" + e.getMessage());
        }
    }
}
